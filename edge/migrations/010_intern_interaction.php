<?php

declare(strict_types=1);

/**
 * Two-way admin↔intern messages and private notes kept by interns.
 */

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS intern_messages (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            intern_id  INT UNSIGNED NOT NULL,
            sender_id  INT UNSIGNED NOT NULL,
            body       TEXT NOT NULL,
            read_at    DATETIME NULL,
            created_at VARCHAR(32) NOT NULL,
            KEY idx_intern_messages_thread (intern_id, created_at),
            CONSTRAINT fk_intern_messages_intern FOREIGN KEY (intern_id)
                REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_intern_messages_sender FOREIGN KEY (sender_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS intern_personal_notes (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            intern_id  INT UNSIGNED NOT NULL,
            title      VARCHAR(255) NOT NULL,
            body       LONGTEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL,
            KEY idx_intern_personal_notes_intern (intern_id, created_at),
            CONSTRAINT fk_intern_personal_notes_intern FOREIGN KEY (intern_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
