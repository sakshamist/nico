-- Sprint 1 core schema.
--
-- Entities follow ARCH.md section 7. Status vocabularies are constrained in the
-- data layer (FR-10): application code cannot write a value outside these sets,
-- and CHECK constraints make that a database-level guarantee where supported.

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('student', 'teacher', 'admin') NOT NULL DEFAULT 'student',
    created_at    VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resources (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    source_type ENUM('pdf', 'image', 'audio', 'video', 'text', 'link') NOT NULL,
    status      ENUM('uploaded', 'queued', 'ingesting', 'modelling',
                     'generating', 'done', 'failed') NOT NULL DEFAULT 'uploaded',
    created_at  VARCHAR(32) NOT NULL,
    KEY idx_resources_user_created (user_id, created_at),
    CONSTRAINT fk_resources_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resource_id INT UNSIGNED NOT NULL,
    kind        ENUM('raw', 'extracted', 'output') NOT NULL DEFAULT 'raw',
    path        VARCHAR(512) NOT NULL,
    mime        VARCHAR(191) NOT NULL,
    bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256      CHAR(64) NOT NULL DEFAULT '',
    created_at  VARCHAR(32) NOT NULL,
    KEY idx_files_resource_kind (resource_id, kind),
    CONSTRAINT fk_files_resource FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resource_id      INT UNSIGNED NOT NULL,
    type             ENUM('ocr', 'parse', 'transcribe', 'embed',
                         'generate_quiz', 'generate_lesson', 'generate_material') NOT NULL,
    status           ENUM('queued', 'claimed', 'running', 'done', 'failed', 'dead')
                     NOT NULL DEFAULT 'queued',
    payload          JSON NULL,
    attempts         INT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME NULL,
    worker_id        VARCHAR(191) NULL,
    created_at       VARCHAR(32) NOT NULL,
    updated_at       VARCHAR(32) NOT NULL,
    -- The claim query's access path (Sprint 3).
    KEY idx_jobs_status_created (status, created_at),
    KEY idx_jobs_resource (resource_id),
    KEY idx_jobs_lease (status, lease_expires_at),
    CONSTRAINT fk_jobs_resource FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS extracted_text (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resource_id INT UNSIGNED NOT NULL,
    text        LONGTEXT NOT NULL,
    meta        JSON NULL,
    created_at  VARCHAR(32) NOT NULL,
    KEY idx_extracted_resource (resource_id),
    CONSTRAINT fk_extracted_resource FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_refs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resource_id INT UNSIGNED NOT NULL,
    chunk_id    VARCHAR(191) NOT NULL,
    vector_ref  VARCHAR(512) NOT NULL,
    text        LONGTEXT NOT NULL,
    created_at  VARCHAR(32) NOT NULL,
    KEY idx_knowledge_resource (resource_id),
    CONSTRAINT fk_knowledge_resource FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outputs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resource_id INT UNSIGNED NOT NULL,
    type        ENUM('quiz', 'lesson', 'material') NOT NULL,
    content     JSON NOT NULL,
    status      ENUM('generating', 'ready', 'invalid', 'failed') NOT NULL DEFAULT 'generating',
    published   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  VARCHAR(32) NOT NULL,
    updated_at  VARCHAR(32) NOT NULL,
    KEY idx_outputs_resource_type (resource_id, type),
    CONSTRAINT fk_outputs_resource FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_posts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    output_id    INT UNSIGNED NOT NULL,
    slug         VARCHAR(191) NOT NULL,
    title        VARCHAR(255) NOT NULL,
    body         LONGTEXT NOT NULL,
    published_at VARCHAR(32) NULL,
    created_at   VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_blog_slug (slug),
    KEY idx_blog_output (output_id),
    CONSTRAINT fk_blog_output FOREIGN KEY (output_id) REFERENCES outputs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
