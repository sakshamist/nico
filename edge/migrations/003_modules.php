<?php

declare(strict_types=1);

/**
 * Module substrate (M2–M6).
 *
 * Adds the tables the knowledge, assessment, learning-progress, and activity
 * modules need. Generated outputs themselves live in the shared `artifacts`
 * table from M0; these tables hold inputs, attempts/progress, and M6 activity
 * state. Every statement is guarded so the migration is safe to re-run.
 */

return static function (PDO $pdo): void {
    $exec = static fn (string $sql): int|false => $pdo->exec($sql);

    $exec(
        "CREATE TABLE IF NOT EXISTS knowledge_sources (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            project_id  INT UNSIGNED NULL,
            resource_id INT UNSIGNED NOT NULL,
            title       VARCHAR(255) NOT NULL,
            kind        ENUM('note', 'brief', 'transcript') NOT NULL DEFAULT 'note',
            body        LONGTEXT NOT NULL,
            status      ENUM('ready', 'archived') NOT NULL DEFAULT 'ready',
            created_at  VARCHAR(32) NOT NULL,
            updated_at  VARCHAR(32) NOT NULL,
            KEY idx_knowledge_sources_user (user_id, created_at),
            KEY idx_knowledge_sources_project (project_id),
            CONSTRAINT fk_knowledge_sources_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_knowledge_sources_project FOREIGN KEY (project_id)
                REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_knowledge_sources_resource FOREIGN KEY (resource_id)
                REFERENCES resources (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS knowledge_chunks (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_id   INT UNSIGNED NOT NULL,
            ordinal     INT UNSIGNED NOT NULL,
            text        LONGTEXT NOT NULL,
            token_count INT UNSIGNED NOT NULL DEFAULT 0,
            content_hash CHAR(64) NOT NULL,
            created_at  VARCHAR(32) NOT NULL,
            UNIQUE KEY uq_knowledge_chunk (source_id, ordinal),
            KEY idx_knowledge_chunks_source (source_id),
            CONSTRAINT fk_knowledge_chunks_source FOREIGN KEY (source_id)
                REFERENCES knowledge_sources (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS learning_progress (
            user_id     INT UNSIGNED NOT NULL,
            artifact_id INT UNSIGNED NOT NULL,
            position    INT UNSIGNED NOT NULL DEFAULT 0,
            completed   TINYINT(1) NOT NULL DEFAULT 0,
            updated_at  VARCHAR(32) NOT NULL,
            PRIMARY KEY (user_id, artifact_id),
            KEY idx_learning_progress_artifact (artifact_id),
            CONSTRAINT fk_learning_progress_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_learning_progress_artifact FOREIGN KEY (artifact_id)
                REFERENCES artifacts (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS assessment_attempts (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            artifact_id   INT UNSIGNED NOT NULL,
            user_id       INT UNSIGNED NOT NULL,
            project_id    INT UNSIGNED NULL,
            status        ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
            current_index INT UNSIGNED NOT NULL DEFAULT 0,
            answers       JSON NULL,
            score         INT UNSIGNED NULL,
            total         INT UNSIGNED NOT NULL DEFAULT 0,
            feedback      JSON NULL,
            created_at    VARCHAR(32) NOT NULL,
            updated_at    VARCHAR(32) NOT NULL,
            KEY idx_attempt_user_artifact (user_id, artifact_id),
            KEY idx_attempt_project (project_id),
            CONSTRAINT fk_attempt_artifact FOREIGN KEY (artifact_id)
                REFERENCES artifacts (id) ON DELETE CASCADE,
            CONSTRAINT fk_attempt_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_attempt_project FOREIGN KEY (project_id)
                REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS activities (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id  INT UNSIGNED NOT NULL,
            kind        ENUM('hackathon', 'event') NOT NULL,
            title       VARCHAR(255) NOT NULL,
            description TEXT NULL,
            timezone    VARCHAR(64) NOT NULL DEFAULT 'UTC',
            status      ENUM('draft', 'scheduled', 'live', 'completed', 'cancelled')
                        NOT NULL DEFAULT 'scheduled',
            visibility  ENUM('workspace', 'public') NOT NULL DEFAULT 'workspace',
            starts_at   DATETIME NULL,
            ends_at     DATETIME NULL,
            join_url    VARCHAR(512) NULL,
            location    VARCHAR(255) NULL,
            rules_url   VARCHAR(512) NULL,
            capacity    INT UNSIGNED NULL,
            meta        JSON NULL,
            created_by  INT UNSIGNED NULL,
            created_at  VARCHAR(32) NOT NULL,
            updated_at  VARCHAR(32) NOT NULL,
            KEY idx_activities_project (project_id, starts_at),
            KEY idx_activities_kind (kind, status),
            CONSTRAINT fk_activities_project FOREIGN KEY (project_id)
                REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_activities_created_by FOREIGN KEY (created_by)
                REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS activity_teams (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_id INT UNSIGNED NOT NULL,
            name        VARCHAR(191) NOT NULL,
            created_by  INT UNSIGNED NULL,
            created_at  VARCHAR(32) NOT NULL,
            UNIQUE KEY uq_activity_team_name (activity_id, name),
            CONSTRAINT fk_activity_teams_activity FOREIGN KEY (activity_id)
                REFERENCES activities (id) ON DELETE CASCADE,
            CONSTRAINT fk_activity_teams_created_by FOREIGN KEY (created_by)
                REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS activity_team_members (
            team_id   INT UNSIGNED NOT NULL,
            user_id   INT UNSIGNED NOT NULL,
            role      ENUM('lead', 'member') NOT NULL DEFAULT 'member',
            joined_at VARCHAR(32) NOT NULL,
            PRIMARY KEY (team_id, user_id),
            KEY idx_team_members_user (user_id),
            CONSTRAINT fk_team_members_team FOREIGN KEY (team_id)
                REFERENCES activity_teams (id) ON DELETE CASCADE,
            CONSTRAINT fk_team_members_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS event_rsvps (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_id INT UNSIGNED NOT NULL,
            user_id     INT UNSIGNED NOT NULL,
            status      ENUM('going', 'waitlist', 'cancelled') NOT NULL DEFAULT 'going',
            created_at  VARCHAR(32) NOT NULL,
            updated_at  VARCHAR(32) NOT NULL,
            UNIQUE KEY uq_event_rsvp (activity_id, user_id),
            KEY idx_event_rsvps_activity (activity_id, status),
            CONSTRAINT fk_event_rsvps_activity FOREIGN KEY (activity_id)
                REFERENCES activities (id) ON DELETE CASCADE,
            CONSTRAINT fk_event_rsvps_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS activity_submissions (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_id INT UNSIGNED NOT NULL,
            team_id     INT UNSIGNED NULL,
            user_id     INT UNSIGNED NOT NULL,
            title       VARCHAR(255) NOT NULL,
            content     TEXT NULL,
            artifact_id INT UNSIGNED NULL,
            created_at  VARCHAR(32) NOT NULL,
            KEY idx_activity_submissions_activity (activity_id, created_at),
            CONSTRAINT fk_activity_submissions_activity FOREIGN KEY (activity_id)
                REFERENCES activities (id) ON DELETE CASCADE,
            CONSTRAINT fk_activity_submissions_team FOREIGN KEY (team_id)
                REFERENCES activity_teams (id) ON DELETE SET NULL,
            CONSTRAINT fk_activity_submissions_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_activity_submissions_artifact FOREIGN KEY (artifact_id)
                REFERENCES artifacts (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
