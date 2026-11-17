<?php

declare(strict_types=1);

/**
 * Slice A: material assigned by an admin to a specific intern.
 */

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS intern_material_assignments (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            intern_id   INT UNSIGNED NOT NULL,
            source_id   INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED NOT NULL,
            created_at  VARCHAR(32) NOT NULL,
            UNIQUE KEY uq_intern_material (intern_id, source_id),
            KEY idx_intern_material_intern (intern_id, created_at),
            CONSTRAINT fk_intern_material_intern FOREIGN KEY (intern_id)
                REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_intern_material_source FOREIGN KEY (source_id)
                REFERENCES knowledge_sources (id) ON DELETE CASCADE,
            CONSTRAINT fk_intern_material_admin FOREIGN KEY (assigned_by)
                REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
