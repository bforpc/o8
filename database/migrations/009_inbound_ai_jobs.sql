CREATE TABLE IF NOT EXISTS ai_configurations (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    external_processing_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    endpoint VARCHAR(768) NOT NULL,
    primary_model VARCHAR(190) NOT NULL,
    fallback_model VARCHAR(190) NULL,
    instruction_text MEDIUMTEXT NOT NULL,
    timeout_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    ciphertext LONGBLOB NULL,
    key_id VARCHAR(100) NULL,
    crypto_version SMALLINT UNSIGNED NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inbound_ai_job_items (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    ai_run_id BIGINT UNSIGNED NOT NULL,
    inbound_item_id BIGINT UNSIGNED NOT NULL,
    expected_revision INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    error_code VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    UNIQUE KEY inbound_ai_job_run (tenant_id, ai_run_id),
    UNIQUE KEY inbound_ai_job_item (tenant_id, job_id, inbound_item_id),
    INDEX inbound_ai_job_status (tenant_id, job_id, status, id),
    FOREIGN KEY (tenant_id, job_id) REFERENCES background_jobs(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, ai_run_id) REFERENCES inbound_ai_runs(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, inbound_item_id) REFERENCES inbound_items(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
