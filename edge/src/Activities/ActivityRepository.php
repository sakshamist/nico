<?php

declare(strict_types=1);

namespace Nico\Edge\Activities;

use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Projects\MembershipRepository;
use Nico\Edge\Support\Database;
use PDO;
use Throwable;

/**
 * M6 activities: hackathons (team builds) and events/seminars (speaker-led).
 *
 * Both are hosted inside an M5 project workspace and share scheduling/visibility
 * fields; type-specific details live in the `meta` JSON so the two page types can
 * diverge without schema churn.
 */
final class ActivityRepository
{
    public const KINDS = ['hackathon', 'event'];

    public const STATUSES = ['draft', 'scheduled', 'live', 'completed', 'cancelled'];

    public const VISIBILITY = ['workspace', 'public'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @param array<string,mixed> $data */
    public function create(int $projectId, int $userId, array $data): int
    {
        $kind = (string) ($data['kind'] ?? '');
        $title = trim((string) ($data['title'] ?? ''));
        $status = (string) ($data['status'] ?? 'scheduled');
        $visibility = (string) ($data['visibility'] ?? 'workspace');

        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown activity kind: {$kind}");
        }

        if ($title === '' || strlen($title) > 255) {
            throw new \InvalidArgumentException('Activity title must be 1-255 characters.');
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown activity status: {$status}");
        }

        if (!in_array($visibility, self::VISIBILITY, true)) {
            throw new \InvalidArgumentException("Unknown visibility: {$visibility}");
        }

        $starts = self::normalizeDateTime($data['starts_at'] ?? null);
        $ends = self::normalizeDateTime($data['ends_at'] ?? null);

        if ($starts !== null && $ends !== null && strcmp($ends, $starts) < 0) {
            throw new \InvalidArgumentException('End time cannot be before start time.');
        }

        $capacity = isset($data['capacity']) && $data['capacity'] !== ''
            ? (int) $data['capacity']
            : null;

        if ($capacity !== null && $capacity < 1) {
            throw new \InvalidArgumentException('Capacity must be at least 1 when set.');
        }

        $meta = isset($data['meta']) ? $data['meta'] : null;

        if (is_array($meta) && isset($meta['team_size_max'])) {
            $teamSize = (int) $meta['team_size_max'];

            if ($teamSize < 1 || $teamSize > 100) {
                throw new \InvalidArgumentException('Team size must be between 1 and 100.');
            }

            $meta['team_size_max'] = $teamSize;
        }

        $now = gmdate('c');

        $this->pdo->prepare(
            'INSERT INTO activities
                (project_id, kind, title, description, timezone, status, visibility,
                 starts_at, ends_at, join_url, location, rules_url, capacity, meta,
                 created_by, created_at, updated_at)
             VALUES
                (:project, :kind, :title, :description, :timezone, :status, :visibility,
                 :starts, :ends, :join_url, :location, :rules_url, :capacity, :meta,
                 :created_by, :now, :now_updated)'
        )->execute([
            'project'     => $projectId,
            'kind'        => $kind,
            'title'       => $title,
            'description' => ($data['description'] ?? '') !== '' ? (string) $data['description'] : null,
            'timezone'    => ($data['timezone'] ?? '') !== '' ? (string) $data['timezone'] : 'UTC',
            'status'      => $status,
            'visibility'  => $visibility,
            'starts'      => $starts,
            'ends'        => $ends,
            'join_url'    => ($data['join_url'] ?? '') !== '' ? (string) $data['join_url'] : null,
            'location'    => ($data['location'] ?? '') !== '' ? (string) $data['location'] : null,
            'rules_url'   => ($data['rules_url'] ?? '') !== '' ? (string) $data['rules_url'] : null,
            'capacity'    => $capacity,
            'meta'        => $meta !== null
                ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            'created_by'  => $userId,
            'now'         => $now,
            'now_updated' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(int $activityId, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown activity status: {$status}");
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM activities WHERE id = :id LIMIT 1');
        $exists->execute(['id' => $activityId]);

        if ($exists->fetchColumn() === false) {
            throw new \InvalidArgumentException('Activity not found.');
        }

        $this->pdo->prepare(
            'UPDATE activities SET status = :status, updated_at = :now WHERE id = :id'
        )->execute(['status' => $status, 'now' => gmdate('c'), 'id' => $activityId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, p.title AS project_title,
                    (SELECT COUNT(*) FROM activity_teams t WHERE t.activity_id = a.id) AS team_count,
                    (SELECT COUNT(*) FROM activity_submissions s WHERE s.activity_id = a.id) AS submission_count
               FROM activities a
               JOIN projects p ON p.id = a.project_id
              WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function listByProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, p.title AS project_title,
                    (SELECT COUNT(*) FROM activity_teams t WHERE t.activity_id = a.id) AS team_count,
                    (SELECT COUNT(*) FROM activity_submissions s WHERE s.activity_id = a.id) AS submission_count
               FROM activities a
               JOIN projects p ON p.id = a.project_id
              WHERE a.project_id = :project
              ORDER BY (a.starts_at IS NULL) ASC, a.starts_at ASC, a.id DESC'
        );
        $stmt->execute(['project' => $projectId]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $activity */
    public function canView(array $activity, int $userId, string $role): bool
    {
        if (($activity['visibility'] ?? 'workspace') === 'public') {
            return true;
        }

        return (new MembershipRepository($this->pdo))
            ->canView((int) $activity['project_id'], $userId, $role);
    }

    /** @return list<array<string,mixed>> */
    public function teams(int $activityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, COUNT(m.user_id) AS member_count
               FROM activity_teams t
               LEFT JOIN activity_team_members m ON m.team_id = t.id
              WHERE t.activity_id = :activity
              GROUP BY t.id
              ORDER BY t.id ASC'
        );
        $stmt->execute(['activity' => $activityId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['activity_id'] = (int) $row['activity_id'];
            $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
            $row['member_count'] = (int) $row['member_count'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function teamMembers(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.user_id, m.role, m.joined_at, u.email
               FROM activity_team_members m
               JOIN users u ON u.id = m.user_id
              WHERE m.team_id = :team
              ORDER BY m.joined_at ASC'
        );
        $stmt->execute(['team' => $teamId]);

        return array_map(static function (array $row): array {
            $row['user_id'] = (int) $row['user_id'];

            return $row;
        }, $stmt->fetchAll());
    }

    public function createTeam(int $activityId, string $name, int $userId): int
    {
        $name = trim($name);

        if ($name === '' || strlen($name) > 191) {
            throw new \InvalidArgumentException('Team name must be 1-191 characters.');
        }

        $now = gmdate('c');
        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO activity_teams (activity_id, name, created_by, created_at)
                 VALUES (:activity, :name, :user, :now)'
            )->execute([
                'activity' => $activityId,
                'name'     => $name,
                'user'     => $userId,
                'now'      => $now,
            ]);

            $teamId = (int) $this->pdo->lastInsertId();

            $this->joinTeam($teamId, $userId, 'lead');

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $teamId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($e instanceof \PDOException && str_contains($e->getMessage(), 'uq_activity_team_name')) {
                throw new \InvalidArgumentException('A team with that name already exists.');
            }

            throw $e;
        }
    }

    public function joinTeam(int $teamId, int $userId, string $role = 'member'): void
    {
        if (!in_array($role, ['lead', 'member'], true)) {
            throw new \InvalidArgumentException("Unknown team role: {$role}");
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $team = $this->pdo->prepare(
                'SELECT t.activity_id, a.meta
                   FROM activity_teams t
                   JOIN activities a ON a.id = t.activity_id
                  WHERE t.id = :team
                  LIMIT 1 FOR UPDATE'
            );
            $team->execute(['team' => $teamId]);
            $row = $team->fetch();

            if ($row === false) {
                throw new \InvalidArgumentException('Unknown team.');
            }

            $existing = $this->pdo->prepare(
                'SELECT role FROM activity_team_members WHERE team_id = :team AND user_id = :user LIMIT 1'
            );
            $existing->execute(['team' => $teamId, 'user' => $userId]);
            $currentRole = $existing->fetchColumn();

            if ($currentRole !== false) {
                if ($ownTransaction) {
                    $this->pdo->commit();
                }
                return;
            }

            $meta = is_string($row['meta'] ?? null) && $row['meta'] !== ''
                ? json_decode((string) $row['meta'], true)
                : [];
            $max = (int) ($meta['team_size_max'] ?? 0);

            if ($max > 0) {
                $count = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM activity_team_members WHERE team_id = :team'
                );
                $count->execute(['team' => $teamId]);

                if ((int) $count->fetchColumn() >= $max) {
                    throw new \InvalidArgumentException('That team is full.');
                }
            }

            $this->pdo->prepare(
                'INSERT INTO activity_team_members (team_id, user_id, role, joined_at)
                 VALUES (:team, :user, :role, :now)'
            )->execute([
                'team' => $teamId,
                'user' => $userId,
                'role' => $role,
                'now'  => gmdate('c'),
            ]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function myTeam(int $activityId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*
               FROM activity_teams t
               JOIN activity_team_members m ON m.team_id = t.id
              WHERE t.activity_id = :activity AND m.user_id = :user
              LIMIT 1'
        );
        $stmt->execute(['activity' => $activityId, 'user' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['activity_id'] = (int) $row['activity_id'];

        return $row;
    }

    public function rsvp(int $activityId, int $userId): string
    {
        $activity = $this->find($activityId);

        if ($activity === null) {
            throw new \InvalidArgumentException('Unknown activity.');
        }

        $existing = $this->pdo->prepare(
            'SELECT status FROM event_rsvps WHERE activity_id = :activity AND user_id = :user LIMIT 1'
        );
        $existing->execute(['activity' => $activityId, 'user' => $userId]);
        $current = $existing->fetchColumn();

        if ($current === 'going') {
            return 'going';
        }

        $status = 'going';

        if ($activity['capacity'] !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM event_rsvps
                  WHERE activity_id = :activity AND status = \'going\''
            );
            $stmt->execute(['activity' => $activityId]);

            if ((int) $stmt->fetchColumn() >= (int) $activity['capacity']) {
                $status = 'waitlist';
            }
        }

        $this->pdo->prepare(
            'INSERT INTO event_rsvps (activity_id, user_id, status, created_at, updated_at)
             VALUES (:activity, :user, :status, :now, :now_updated)
             ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)'
        )->execute([
            'activity'     => $activityId,
            'user'         => $userId,
            'status'       => $status,
            'now'          => gmdate('c'),
            'now_updated'  => gmdate('c'),
        ]);

        return $status;
    }

    /** @return array{going:int,waitlist:int,my_status:?string} */
    public function rsvpCounts(int $activityId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS total FROM event_rsvps
              WHERE activity_id = :activity GROUP BY status'
        );
        $stmt->execute(['activity' => $activityId]);
        $counts = ['going' => 0, 'waitlist' => 0, 'my_status' => null];

        foreach ($stmt->fetchAll() as $row) {
            if ($row['status'] === 'going') {
                $counts['going'] = (int) $row['total'];
            } elseif ($row['status'] === 'waitlist') {
                $counts['waitlist'] = (int) $row['total'];
            }
        }

        $mine = $this->pdo->prepare(
            'SELECT status FROM event_rsvps WHERE activity_id = :activity AND user_id = :user LIMIT 1'
        );
        $mine->execute(['activity' => $activityId, 'user' => $userId]);
        $status = $mine->fetchColumn();
        $counts['my_status'] = $status === false ? null : (string) $status;

        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function submissions(int $activityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, u.email, t.name AS team_name
               FROM activity_submissions s
               JOIN users u ON u.id = s.user_id
               LEFT JOIN activity_teams t ON t.id = s.team_id
              WHERE s.activity_id = :activity
              ORDER BY s.created_at DESC, s.id DESC'
        );
        $stmt->execute(['activity' => $activityId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['activity_id'] = (int) $row['activity_id'];
            $row['team_id'] = $row['team_id'] === null ? null : (int) $row['team_id'];
            $row['user_id'] = (int) $row['user_id'];
            $row['artifact_id'] = $row['artifact_id'] === null ? null : (int) $row['artifact_id'];

            return $row;
        }, $stmt->fetchAll());
    }

    public function addSubmission(
        int $activityId,
        int $userId,
        string $title,
        ?string $content,
        ?int $teamId = null,
    ): int {
        $title = trim($title);

        if ($title === '' || strlen($title) > 255) {
            throw new \InvalidArgumentException('Submission title must be 1-255 characters.');
        }

        if ($teamId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM activity_teams WHERE id = :team AND activity_id = :activity'
            );
            $stmt->execute(['team' => $teamId, 'activity' => $activityId]);

            if ($stmt->fetchColumn() === false) {
                throw new \InvalidArgumentException('That team does not belong to this activity.');
            }

            $member = $this->pdo->prepare(
                'SELECT 1 FROM activity_team_members WHERE team_id = :team AND user_id = :user'
            );
            $member->execute(['team' => $teamId, 'user' => $userId]);

            if ($member->fetchColumn() === false) {
                throw new \InvalidArgumentException('You must join that team before submitting for it.');
            }
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $now = gmdate('c');
            $this->pdo->prepare(
                'INSERT INTO activity_submissions
                    (activity_id, team_id, user_id, title, content, created_at)
                 VALUES (:activity, :team, :user, :title, :content, :now)'
            )->execute([
                'activity' => $activityId,
                'team'     => $teamId,
                'user'     => $userId,
                'title'    => $title,
                'content'  => $content,
                'now'      => $now,
            ]);

            $submissionId = (int) $this->pdo->lastInsertId();

            $artifact = (new ArtifactRepository($this->pdo))->create([
                'project_id' => $this->projectIdForActivity($activityId),
                'module'     => 'M6',
                'type'       => 'activity_submission_' . $submissionId,
                'content'    => [
                    'title'    => $title,
                    'sections' => [[
                        'heading' => 'Submission',
                        'body'    => $content !== null && trim($content) !== '' ? $content : 'No details provided.',
                    ]],
                    'citations' => [[
                        'kind'  => 'activity',
                        'id'    => $activityId,
                        'label' => 'Activity',
                    ]],
                ],
                'provenance' => [
                    'activity_id'   => $activityId,
                    'submission_id' => $submissionId,
                    'team_id'       => $teamId,
                    'author_id'     => $userId,
                ],
                'created_by' => $userId,
            ]);

            $this->pdo->prepare(
                'UPDATE activity_submissions SET artifact_id = :artifact WHERE id = :id'
            )->execute(['artifact' => $artifact, 'id' => $submissionId]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $submissionId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function projectIdForActivity(int $activityId): int
    {
        $stmt = $this->pdo->prepare('SELECT project_id FROM activities WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $activityId]);
        $projectId = $stmt->fetchColumn();

        if ($projectId === false) {
            throw new \InvalidArgumentException('Activity not found.');
        }

        return (int) $projectId;
    }

    public static function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = str_replace('T', ' ', trim((string) $value));

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $text) === 1) {
            $text .= ':00';
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text, new \DateTimeZone('UTC'));

        if ($dt === false) {
            throw new \InvalidArgumentException('Times must be formatted as YYYY-MM-DD HH:MM.');
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['project_id'] = (int) $row['project_id'];
        $row['capacity'] = $row['capacity'] === null ? null : (int) $row['capacity'];
        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
        $row['team_count'] = (int) ($row['team_count'] ?? 0);
        $row['submission_count'] = (int) ($row['submission_count'] ?? 0);
        $row['meta'] = is_string($row['meta'] ?? null) && $row['meta'] !== ''
            ? json_decode((string) $row['meta'], true)
            : [];

        return $row;
    }
}
