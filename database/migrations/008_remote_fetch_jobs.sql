CREATE TABLE IF NOT EXISTS source_fetch_items (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    remote_key_hash CHAR(64) NOT NULL,
    locator_json JSON NOT NULL,
    original_name VARCHAR(768) NOT NULL,
    expected_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expected_mime VARCHAR(100) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    error_code VARCHAR(64) NULL,
    inbound_item_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    UNIQUE KEY source_fetch_job_remote (tenant_id, job_id, remote_key_hash),
    UNIQUE KEY source_fetch_items_tenant_id (tenant_id, id),
    INDEX source_fetch_job_status (tenant_id, job_id, status, id),
    FOREIGN KEY (tenant_id, job_id) REFERENCES background_jobs(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, inbound_item_id) REFERENCES inbound_items(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inbound_files (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    inbound_item_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(16) NOT NULL,
    storage_key VARCHAR(64) NOT NULL DEFAULT 'main',
    relative_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(768) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY inbound_file_role (tenant_id, inbound_item_id, role),
    UNIQUE KEY inbound_file_path (tenant_id, storage_key, relative_path),
    UNIQUE KEY inbound_files_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id, inbound_item_id) REFERENCES inbound_items(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, storage_key) REFERENCES storage_locations(tenant_id, storage_key) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE background_jobs ADD COLUMN error_code VARCHAR(64) NULL AFTER failed_count;
