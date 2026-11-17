<?php

declare(strict_types=1);

namespace Nico\Edge\Tasks;

use Nico\Edge\People\ProfileRepository;
use Nico\Edge\Projects\MembershipRepository;
use Nico\Edge\Support\Database;
use PDO;

/**
 * Capability-based task assignment (M5 MVP rule).
 *
 * 1. Hard filter: every required capability is present at or above the required
 *    level; the member is not `away`; open tasks are below their concurrency cap.
 * 2. Best fit: score = capability level margin - open task count.
 * 3. Tie-break: least recently assigned first (never-assigned ranks ahead of
 *    anyone already assigned), then lowest user id.
 *
 * Manual overrides bypass the filter deliberately but are always logged.
 */
final class AssignmentService
{
    private TaskRepository $tasks;

    private MembershipRepository $memberships;

    private ProfileRepository $profiles;

    public function __construct(
        ?PDO $pdo = null,
        ?TaskRepository $tasks = null,
        ?MembershipRepository $memberships = null,
        ?ProfileRepository $profiles = null,
    ) {
        $pdo ??= Database::pdo();
        $this->tasks = $tasks ?? new TaskRepository($pdo);
        $this->memberships = $memberships ?? new MembershipRepository($pdo);
        $this->profiles = $profiles ?? new ProfileRepository($pdo);
    }

    /**
     * @return array{
     *   task_id:int,state:string,assigned_to:?int,score:?int,rationale:array<string,mixed>
     * }
     */
    public function assignBestFit(int $taskId, ?int $actorId = null, bool $force = false): array
    {
        $task = $this->tasks->find($taskId);

        if ($task === null) {
            throw new \InvalidArgumentException("Unknown task: {$taskId}");
        }

        if (!$force && $task['assignment_state'] === 'assigned' && $task['assignee_id'] !== null) {
            return [
                'task_id'     => $taskId,
                'state'       => 'assigned',
                'assigned_to' => (int) $task['assignee_id'],
                'score'       => null,
                'rationale'   => ['reason' => 'already_assigned'],
            ];
        }

        $members = $this->memberships->members((int) $task['project_id']);
        $userIds = array_map(static fn (array $m): int => (int) $m['user_id'], $members);

        $profiles = $this->profiles->profilesFor($userIds);
        $capabilities = $this->profiles->capabilitiesFor($userIds);
        $openCounts = $this->tasks->openTaskCounts($userIds);
        $lastAssigned = $this->tasks->lastAssignedAt($userIds);
        $requirements = self::requirements($task['requirements']);

        $candidates = [];
        $considered = [];

        foreach ($userIds as $userId) {
            $profile = $profiles[$userId];
            $caps = $capabilities[$userId] ?? [];
            $open = $openCounts[$userId] ?? 0;

            if ($profile['availability'] === 'away') {
                $considered[] = ['user_id' => $userId, 'excluded' => 'away'];
                continue;
            }

            if ($open >= $profile['max_open_tasks']) {
                $considered[] = ['user_id' => $userId, 'excluded' => 'at_concurrency_cap'];
                continue;
            }

            $margin = 0;
            $missing = null;

            foreach ($requirements as $requirement) {
                $level = $caps[$requirement['tag']] ?? 0;

                if ($level < $requirement['level']) {
                    $missing = $requirement['tag'];
                    break;
                }

                $margin += $level - $requirement['level'];
            }

            if ($missing !== null) {
                $considered[] = ['user_id' => $userId, 'excluded' => "missing_capability:{$missing}"];
                continue;
            }

            $candidates[] = [
                'user_id'          => $userId,
                'score'            => $margin - $open,
                'capability_margin' => $margin,
                'open_tasks'       => $open,
                'last_assigned_at' => $lastAssigned[$userId] ?? null,
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            $aLast = $a['last_assigned_at'];
            $bLast = $b['last_assigned_at'];

            if ($aLast !== $bLast) {
                if ($aLast === null) {
                    return -1;
                }

                if ($bLast === null) {
                    return 1;
                }

                return strcmp((string) $aLast, (string) $bLast);
            }

            return $a['user_id'] <=> $b['user_id'];
        });

        if ($candidates === []) {
            $rationale = [
                'reason'       => 'no_candidates',
                'requirements' => $requirements,
                'considered'   => $considered,
            ];

            $this->tasks->assign($taskId, null, $actorId, 'auto', $rationale, 'needs_human');

            return [
                'task_id'     => $taskId,
                'state'       => 'needs_human',
                'assigned_to' => null,
                'score'       => null,
                'rationale'   => $rationale,
            ];
        }

        $best = $candidates[0];
        $rationale = [
            'reason'              => 'best_fit',
            'requirements'        => $requirements,
            'score'               => $best['score'],
            'capability_margin'   => $best['capability_margin'],
            'open_tasks'          => $best['open_tasks'],
            'last_assigned_at'    => $best['last_assigned_at'],
            'tie_break'           => 'score_desc,least_recent_assignment,user_id_asc',
            'candidates_considered' => count($candidates),
            'excluded'            => $considered,
        ];

        $this->tasks->assign($taskId, $best['user_id'], $actorId, 'auto', $rationale, 'assigned');

        return [
            'task_id'     => $taskId,
            'state'       => 'assigned',
            'assigned_to' => $best['user_id'],
            'score'       => $best['score'],
            'rationale'   => $rationale,
        ];
    }

    /** Manual override: bypasses capability filtering but must target a member. */
    public function override(int $taskId, int $userId, ?int $actorId = null, ?string $reason = null): array
    {
        $task = $this->tasks->find($taskId);

        if ($task === null) {
            throw new \InvalidArgumentException("Unknown task: {$taskId}");
        }

        if (!$this->memberships->isMember((int) $task['project_id'], $userId)) {
            throw new \InvalidArgumentException('Assignee is not a member of this project.');
        }

        $rationale = [
            'reason'       => 'manual_override',
            'note'         => $reason,
            'requirements' => self::requirements($task['requirements']),
        ];

        $result = $this->tasks->assign($taskId, $userId, $actorId, 'manual', $rationale, 'assigned');

        return [
            'task_id'     => $taskId,
            'state'       => 'assigned',
            'assigned_to' => $userId,
            'score'       => null,
            'rationale'   => $rationale + $result,
        ];
    }

    public function release(int $taskId, ?int $actorId = null): array
    {
        $task = $this->tasks->find($taskId);

        if ($task === null) {
            throw new \InvalidArgumentException("Unknown task: {$taskId}");
        }

        $this->tasks->assign(
            $taskId,
            null,
            $actorId,
            'release',
            ['reason' => 'released'],
            'unassigned',
        );

        return ['task_id' => $taskId, 'state' => 'unassigned', 'assigned_to' => null];
    }

    /**
     * Accepts {capabilities:[{tag,level}]}, {capabilities:["tag"]} or {tags:["tag"]}.
     *
     * @param mixed $requirements decoded JSON column
     * @return list<array{tag:string,level:int}>
     */
    public static function requirements(mixed $requirements): array
    {
        if (!is_array($requirements)) {
            return [];
        }

        $raw = $requirements['capabilities']
            ?? $requirements['tags']
            ?? (array_is_list($requirements) ? $requirements : []);

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = ['tag' => trim($item), 'level' => 1];
                continue;
            }

            if (is_array($item) && isset($item['tag']) && is_string($item['tag']) && trim($item['tag']) !== '') {
                $out[] = [
                    'tag'   => trim($item['tag']),
                    'level' => max(1, (int) ($item['level'] ?? 1)),
                ];
            }
        }

        return $out;
    }
}
