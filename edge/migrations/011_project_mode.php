<?php

declare(strict_types=1);

/**
 * Private (one intern) and shared (many interns) project journeys.
 */

return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
          LIMIT 1'
    );
    $stmt->execute(['t' => 'projects', 'c' => 'mode']);

    if ($stmt->fetchColumn() === false) {
        $pdo->exec(
            "ALTER TABLE projects
             ADD COLUMN mode ENUM('personal', 'shared') NOT NULL DEFAULT 'shared' AFTER kind"
        );
    }
};
