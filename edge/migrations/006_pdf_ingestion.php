<?php

declare(strict_types=1);

/**
 * M1 minimal PDF ingestion: mark document sources and keep the uploaded file's
 * original name for display and authenticated downloads.
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

    if (!$hasColumn('files', 'original_name')) {
        $pdo->exec('ALTER TABLE files ADD COLUMN original_name VARCHAR(255) NULL AFTER sha256');
    }

    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
         LIMIT 1'
    );
    $stmt->execute(['t' => 'knowledge_sources', 'c' => 'kind']);
    $type = (string) $stmt->fetchColumn();

    if (!str_contains($type, 'document')) {
        $pdo->exec(
            "ALTER TABLE knowledge_sources
             MODIFY COLUMN kind ENUM('note', 'brief', 'transcript', 'document')
             NOT NULL DEFAULT 'note'"
        );
    }
};
