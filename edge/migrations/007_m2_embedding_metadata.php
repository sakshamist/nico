<?php

declare(strict_types=1);

/**
 * M2 hardening: page-level provenance for chunks and model metadata for the
 * local vector references produced by the Ollama embedding path.
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

    if (!$hasColumn('knowledge_chunks', 'page')) {
        $pdo->exec('ALTER TABLE knowledge_chunks ADD COLUMN page INT UNSIGNED NULL AFTER ordinal');
    }

    if (!$hasColumn('knowledge_refs', 'model')) {
        $pdo->exec("ALTER TABLE knowledge_refs ADD COLUMN model VARCHAR(191) NULL AFTER vector_ref");
    }

    if (!$hasColumn('knowledge_refs', 'dimensions')) {
        $pdo->exec('ALTER TABLE knowledge_refs ADD COLUMN dimensions INT UNSIGNED NULL AFTER model');
    }
};
