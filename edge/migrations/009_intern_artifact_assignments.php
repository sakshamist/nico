<?php

declare(strict_types=1);

/**
 * Per-intern copies of generated material. Artifacts without a row here remain
 * shared with every intern assigned to the source material.
 */

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS intern_artifact_assignments (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            intern_id   INT UNSIGNED NOT NULL,
            artifact_id INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED NULL,
            created_at  VARCHAR(32) NOT NULL,
            UNIQUE KEY uq_intern_artifact (intern_id, artifact_id),
            KEY idx_intern_artifact_artifact (artifact_id),
            CONSTRAINT fk_intern_artifact_intern FOREIGN KEY (intern_id)
                REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_intern_artifact_artifact FOREIGN KEY (artifact_id)
                REFERENCES artifacts (id) ON DELETE CASCADE,
            CONSTRAINT fk_intern_artifact_admin FOREIGN KEY (assigned_by)
                REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
