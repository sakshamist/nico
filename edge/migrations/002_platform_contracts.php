<?php

declare(strict_types=1);

/**
 * M0 platform contracts (Phase 1).
 *
 * Adds the shared entities every module depends on — projects/workspaces,
 * membership, capability profiles, tasks/assignments, and the common artifact
 * envelope — plus the job-queue extensions (project-scoped jobs, backoff,
 * lease tokens, result artifacts).
 *
 * This is a PHP migration because MySQL and MariaDB cannot portably express
 * "add column/index/constraint if absent" in plain DDL. Every step is guarded so
 * the migration is safe to re-run on a schema that already has these changes.
 */

return static function (PDO $pdo): void {
    $exec = static fn (string $sql): int|false => $pdo->exec($sql);

    // ---------------------------------------------------------------
    // New tables
    // ---------------------------------------------------------------

    $exec(
        "CREATE TABLE IF NOT EXISTS projects (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_id        INT UNSIGNED NOT NULL,
            title           VARCHAR(255) NOT NULL,
            description     TEXT NULL,
            kind            ENUM('live', 'general') NOT NULL DEFAULT 'general',
            status          ENUM('active', 'archived', 'completed') NOT NULL DEFAULT 'active',
            needs_attention TINYINT(1) NOT NULL DEFAULT 0,
            created_at      VARCHAR(32) NOT NULL,
            updated_at      VARCHAR(32) NOT NULL,
            KEY idx_projects_owner (owner_id, created_at),
            CONSTRAINT fk_projects_owner FOREIGN KEY (owner_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS project_members (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id INT UNSIGNED NOT NULL,
            user_id    INT UNSIGNED NOT NULL,
            role       ENUM('owner', 'organizer', 'member') NOT NULL DEFAULT 'member',
            created_at VARCHAR(32) NOT NULL,
            UNIQUE KEY uq_project_members (project_id, user_id),
            KEY idx_project_members_user (user_id),
            CONSTRAINT fk_project_members_project FOREIGN KEY (project_id)
                REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_project_members_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS user_profiles (
            user_id        INT UNSIGNED PRIMARY KEY,
            max_open_tasks SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            availability   ENUM('available', 'busy', 'away') NOT NULL DEFAULT 'available',
            updated_at     VARCHAR(32) NOT NULL,
            CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS user_capabilities (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            tag        VARCHAR(64) NOT NULL,
            level      TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL,
            UNIQUE KEY uq_user_capability (user_id, tag),
            KEY idx_user_capabilities_tag (tag),
            CONSTRAINT fk_user_capabilities_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS tasks (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id       INT UNSIGNED NOT NULL,
            title            VARCHAR(255) NOT NULL,
            description      TEXT NULL,
            requirements     JSON NULL,
            priority         TINYINT UNSIGNED NOT NULL DEFAULT 3,
            status           ENUM('todo', 'in_progress', 'blocked', 'review', 'done', 'cancelled')
                             NOT NULL DEFAULT 'todo',
            assignment_state ENUM('unassigned', 'suggested', 'assigned', 'needs_human')
                             NOT NULL DEFAULT 'unassigned',
            assignee_id      INT UNSIGNED NULL,
            due_at           DATETIME NULL,
            created_by       INT UNSIGNED NULL,
            created_at       VARCHAR(32) NOT NULL,
            updated_at       VARCHAR(32) NOT NULL,
            KEY idx_tasks_project_status (project_id, status),
            KEY idx_tasks_assignee_status (assignee_id, status),
            CONSTRAINT fk_tasks_project FOREIGN KEY (project_id)
                REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_id)
                REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by)
                REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS task_assignment_log (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id      INT UNSIGNED NOT NULL,
            from_user_id INT UNSIGNED NULL,
            to_user_id   INT UNSIGNED NULL,
            action       ENUM('auto', 'manual', 'release', 'complete') NOT NULL,
            rationale    JSON NULL,
            actor_id     INT UNSIGNED NULL,
            created_at   VARCHAR(32) NOT NULL,
            KEY idx_assignment_log_task (task_id, created_at),
            KEY idx_assignment_log_user_created (to_user_id, created_at),
            CONSTRAINT fk_task_assign_log_task FOREIGN KEY (task_id)
                REFERENCES tasks (id) ON DELETE CASCADE,
            CONSTRAINT fk_task_assign_log_from FOREIGN KEY (from_user_id)
                REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_task_assign_log_to FOREIGN KEY (to_user_id)
                REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_task_assign_log_actor FOREIGN KEY (actor_id)
                REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $exec(
        "CREATE TABLE IF NOT EXISTS artifacts (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id     INT UNSIGNED NULL,
            resource_id    INT UNSIGNED NULL,
            module         VARCHAR(16) NOT NULL,
            type           VARCHAR(64) NOT NULL,
            schema_version INT UNSIGNED NOT NULL DEFAULT 1,
            version        INT UNSIGNED NOT NULL DEFAULT 1,
            supersedes_id  INT UNSIGNED NULL,
            is_current     TINYINT(1) NOT NULL DEFAULT 1,
            content        JSON NULL,
            provenance     JSON NULL,
            status         ENUM('generating', 'ready', 'invalid', 'failed')
                           NOT NULL DEFAULT 'generating',
            created_by     INT UNSIGNED NULL,
            created_at     VARCHAR(32) NOT NULL,
            updated_at     VARCHAR(32) NOT NULL,
            KEY idx_artifacts_project (project_id, module, type),
            KEY idx_artifacts_resource (resource_id, module, type),
            KEY idx_artifacts_current (project_id, resource_id, module, type, is_current),
            CONSTRAINT fk_artifacts_project FOREIGN KEY (project_id)
                REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_artifacts_resource FOREIGN KEY (resource_id)
                REFERENCES resources (id) ON DELETE SET NULL,
            CONSTRAINT fk_artifacts_supersedes FOREIGN KEY (supersedes_id)
                REFERENCES artifacts (id) ON DELETE SET NULL,
            CONSTRAINT fk_artifacts_created_by FOREIGN KEY (created_by)
                REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // ---------------------------------------------------------------
    // jobs extensions
    // ---------------------------------------------------------------

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

    $addColumn = static function (string $table, string $column, string $definition)
        use ($pdo, $hasColumn, $exec): void {
        if (!$hasColumn($table, $column)) {
            $exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    // Jobs may target a project rather than a resource (reports, plans, tasks).
    $addColumn('jobs', 'project_id', 'INT UNSIGNED NULL AFTER resource_id');
    $addColumn('jobs', 'result_artifact_id', 'INT UNSIGNED NULL AFTER project_id');
    $addColumn('jobs', 'lease_token', 'CHAR(36) NULL AFTER worker_id');
    $addColumn('jobs', 'last_error', 'TEXT NULL AFTER lease_token');
    $addColumn('jobs', 'available_at', 'DATETIME NULL AFTER lease_expires_at');
    $addColumn('jobs', 'finished_at', 'DATETIME NULL AFTER available_at');

    // The original schema required a resource; project-scoped jobs do not have one.
    $nullable = $pdo->prepare(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'resource_id'"
    );
    $nullable->execute();
    if ($nullable->fetchColumn() === 'NO') {
        $exec('ALTER TABLE jobs MODIFY COLUMN resource_id INT UNSIGNED NULL');
    }

    // Extend the job-type vocabulary additively; existing values keep their order.
    $typeStmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'type'"
    );
    $typeStmt->execute();
    $jobType = (string) $typeStmt->fetchColumn();

    if (!str_contains($jobType, "'fetch'")) {
        $exec(
            "ALTER TABLE jobs MODIFY COLUMN type ENUM(
                'ocr', 'parse', 'transcribe', 'embed',
                'generate_quiz', 'generate_lesson', 'generate_material',
                'fetch', 'chunk', 'generate_report'
            ) NOT NULL"
        );
    }

    if (!$hasIndex('jobs', 'idx_jobs_claim')) {
        $exec('ALTER TABLE jobs ADD KEY idx_jobs_claim (status, available_at, created_at)');
    }

    if (!$hasConstraint('jobs', 'fk_jobs_project')) {
        $exec(
            'ALTER TABLE jobs ADD CONSTRAINT fk_jobs_project FOREIGN KEY (project_id)
             REFERENCES projects (id) ON DELETE CASCADE'
        );
    }

    if (!$hasConstraint('jobs', 'fk_jobs_result_artifact')) {
        $exec(
            'ALTER TABLE jobs ADD CONSTRAINT fk_jobs_result_artifact FOREIGN KEY (result_artifact_id)
             REFERENCES artifacts (id) ON DELETE SET NULL'
        );
    }
};
