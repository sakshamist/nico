<?php

declare(strict_types=1);

namespace Nico\Edge\People;

use Nico\Edge\Support\Database;
use PDO;

/**
 * Capability profiles used by M5 auto-assignment.
 *
 * Capabilities are a tag plus a level (1-5). A profile carries the concurrency
 * cap and availability signal. Defaults are applied on read so a user who has
 * never edited a profile can still be matched.
 */
final class ProfileRepository
{
    public const AVAILABILITY = ['available', 'busy', 'away'];

    public const DEFAULT_MAX_OPEN_TASKS = 5;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return array{user_id:int,max_open_tasks:int,availability:string} */
    public function get(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, max_open_tasks, availability FROM user_profiles WHERE user_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return [
                'user_id'        => $userId,
                'max_open_tasks' => self::DEFAULT_MAX_OPEN_TASKS,
                'availability'   => 'available',
            ];
        }

        return [
            'user_id'        => $userId,
            'max_open_tasks' => (int) $row['max_open_tasks'],
            'availability'   => (string) $row['availability'],
        ];
    }

    public function update(int $userId, ?int $maxOpenTasks = null, ?string $availability = null): void
    {
        if ($availability !== null && !in_array($availability, self::AVAILABILITY, true)) {
            throw new \InvalidArgumentException("Unknown availability: {$availability}");
        }

        $current = $this->get($userId);
        $max = $maxOpenTasks ?? $current['max_open_tasks'];
        $availability ??= $current['availability'];

        if ($max < 1 || $max > 100) {
            throw new \InvalidArgumentException('max_open_tasks must be between 1 and 100.');
        }

        $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, max_open_tasks, availability, updated_at)
             VALUES (:id, :max, :availability, :now)
             ON DUPLICATE KEY UPDATE
                max_open_tasks = VALUES(max_open_tasks),
                availability = VALUES(availability),
                updated_at = VALUES(updated_at)'
        )->execute([
            'id'           => $userId,
            'max'          => $max,
            'availability' => $availability,
            'now'          => gmdate('c'),
        ]);
    }

    public function setCapability(int $userId, string $tag, int $level): void
    {
        $tag = trim($tag);

        if ($tag === '' || strlen($tag) > 64) {
            throw new \InvalidArgumentException('Capability tag must be 1-64 characters.');
        }

        if ($level < 1 || $level > 5) {
            throw new \InvalidArgumentException('Capability level must be between 1 and 5.');
        }

        $now = gmdate('c');

        $this->pdo->prepare(
            'INSERT INTO user_capabilities (user_id, tag, level, created_at, updated_at)
             VALUES (:user, :tag, :level, :now, :now_updated)
             ON DUPLICATE KEY UPDATE level = VALUES(level), updated_at = VALUES(updated_at)'
        )->execute([
            'user'         => $userId,
            'tag'          => $tag,
            'level'        => $level,
            'now'          => $now,
            'now_updated'  => $now,
        ]);
    }

    public function removeCapability(int $userId, string $tag): void
    {
        $this->pdo->prepare('DELETE FROM user_capabilities WHERE user_id = :user AND tag = :tag')
            ->execute(['user' => $userId, 'tag' => trim($tag)]);
    }

    /** @return array<string,int> tag => level */
    public function capabilities(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tag, level FROM user_capabilities WHERE user_id = :user ORDER BY tag ASC'
        );
        $stmt->execute(['user' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['tag']] = (int) $row['level'];
        }

        return $out;
    }

    /**
     * @param list<int> $userIds
     * @return array<int,array<string,int>>
     */
    public function capabilitiesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $userIds));
        $rows = $this->pdo->query(
            "SELECT user_id, tag, level FROM user_capabilities WHERE user_id IN ({$ids})"
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['user_id']][(string) $row['tag']] = (int) $row['level'];
        }

        return $out;
    }

    /**
     * @param list<int> $userIds
     * @return array<int,array{user_id:int,max_open_tasks:int,availability:string}>
     */
    public function profilesFor(array $userIds): array
    {
        $out = [];

        foreach ($userIds as $id) {
            $out[$id] = $this->get($id);
        }

        return $out;
    }
}
