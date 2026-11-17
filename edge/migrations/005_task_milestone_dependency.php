<?php

declare(strict_types=1);

/**
 * M5 planning context: group tasks into lightweight milestones/phases and let a
 * task declare the task it depends on. Kept on the task row (not a new entity)
 * so the MVP can track phases without a separate scheduler.
 */

return static function (PDO $pdo): void {
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
             LIMIT 1'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

        return $stmt->fetchColumn() !== false;
    };

    $hasIndex = static function (string $table, string $index) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i
             LIMIT 1'
        );
        $stmt->execute(['t' => $table, 'i' => $index]);

        return $stmt->fetchColumn() !== false;
    };

    $hasConstraint = static function (string $table, string $constraint) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :c
             LIMIT 1'
        );
        $stmt->execute(['t' => $table, 'c' => $constraint]);

        return $stmt->fetchColumn() !== false;
    };

    if (!$hasColumn('tasks', 'milestone')) {
        $pdo->exec('ALTER TABLE tasks ADD COLUMN milestone VARCHAR(120) NULL AFTER artifact_id');
    }

    if (!$hasColumn('tasks', 'depends_on_task_id')) {
        $pdo->exec('ALTER TABLE tasks ADD COLUMN depends_on_task_id INT UNSIGNED NULL AFTER milestone');
    }

    if (!$hasIndex('tasks', 'idx_tasks_depends_on')) {
        $pdo->exec('ALTER TABLE tasks ADD KEY idx_tasks_depends_on (depends_on_task_id)');
    }

    if (!$hasConstraint('tasks', 'fk_tasks_depends_on')) {
        $pdo->exec(
            'ALTER TABLE tasks ADD CONSTRAINT fk_tasks_depends_on FOREIGN KEY (depends_on_task_id)
             REFERENCES tasks (id) ON DELETE SET NULL'
        );
    }
};
