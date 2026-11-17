<?php

declare(strict_types=1);

/**
 * M5 task context: optionally link a task to a project artifact (lesson, quiz,
 * source-backed brief, report) so project members can trace work to learning
 * material. Guarded for re-runs like the other PHP migrations.
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

    if (!$hasColumn('tasks', 'artifact_id')) {
        $pdo->exec('ALTER TABLE tasks ADD COLUMN artifact_id INT UNSIGNED NULL AFTER due_at');
    }

    if (!$hasIndex('tasks', 'idx_tasks_artifact')) {
        $pdo->exec('ALTER TABLE tasks ADD KEY idx_tasks_artifact (artifact_id)');
    }

    if (!$hasConstraint('tasks', 'fk_tasks_artifact')) {
        $pdo->exec(
            'ALTER TABLE tasks ADD CONSTRAINT fk_tasks_artifact FOREIGN KEY (artifact_id)
             REFERENCES artifacts (id) ON DELETE SET NULL'
        );
    }
};
