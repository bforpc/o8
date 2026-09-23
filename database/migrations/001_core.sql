-- M2.1 baseline. Versioned; do not modify after applying.
-- Tenant-bound foreign keys, server-side policies remain mandatory.
CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    public_id CHAR(36) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    contact_email VARCHAR(254) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Getrennte Control Plane, keine automatische Mitgliedschaft in einem Mandanten.
CREATE TABLE IF NOT EXISTS platform_operators (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    login VARCHAR(190) NOT NULL UNIQUE,
    display_name VARCHAR(190) NOT NULL,
    email_normalized VARCHAR(254) NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
    bootstrap_pending BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    value_json JSON NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES platform_operators(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_audit_events (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    operator_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (operator_id) REFERENCES platform_operators(id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenant_licenses (
    tenant_id BIGINT UNSIGNED PRIMARY KEY,
    signed_envelope TEXT NOT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Status/Supportende nicht ungeprüft vertrauen: Signatur/Bindung serverseitig prüfen.
-- Installation-ID/Prüfschlüssel im Betreiber-Deployment, private Signierschlüssel extern.

CREATE TABLE IF NOT EXISTS roles (
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(32),
    name VARCHAR(100) NOT NULL,
    system_role BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (tenant_id, code),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS permissions (
    code VARCHAR(100) PRIMARY KEY,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    tenant_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    permission_code VARCHAR(100) NOT NULL,
    scope VARCHAR(16) NOT NULL, -- own / all; kein ungeprüftes Freitextrecht
    PRIMARY KEY (tenant_id, role_code, permission_code),
    FOREIGN KEY (tenant_id, role_code) REFERENCES roles(tenant_id, code) ON DELETE CASCADE,
    FOREIGN KEY (permission_code) REFERENCES permissions(code) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- M2 initialisiert admin / user und deren feste Rechte. Kein öffentlicher Admin-Signup.
-- Noch kein Seed/Deployment: Berechtigungen werden vor M2 mit Server-Policies getestet.

CREATE TABLE IF NOT EXISTS users (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    login VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    email VARCHAR(254) NOT NULL,
    email_normalized VARCHAR(254) NOT NULL,
    email_verified_at DATETIME NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
    role VARCHAR(32) NOT NULL DEFAULT 'user',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1, -- Sessions bei Sperrung/Rechtewechsel widerrufen
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id, role) REFERENCES roles(tenant_id, code) ON DELETE RESTRICT,
    UNIQUE KEY users_tenant_id (tenant_id, id),
    UNIQUE KEY users_login (tenant_id, login),
    UNIQUE KEY users_email_normalized (tenant_id, email_normalized),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS documents (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    owner_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    document_type VARCHAR(32) NOT NULL DEFAULT 'document',
    sender VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    in_inbox BOOLEAN NOT NULL DEFAULT TRUE,
    document_date DATE NULL,
    remind_at DATE NULL,
    memo TEXT NULL,
    ai_data JSON NULL,
    searchable BOOLEAN NOT NULL DEFAULT TRUE,
    expired BOOLEAN NOT NULL DEFAULT FALSE,
    search_text LONGTEXT NULL,
    source_type VARCHAR(32) NOT NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id, owner_id) REFERENCES users(tenant_id, id),
    INDEX documents_active_date (tenant_id, deleted_at, document_date, id),
    INDEX documents_owner_date (tenant_id, owner_id, deleted_at, document_date, id),
    INDEX documents_inbox (tenant_id, in_inbox, deleted_at, document_date, id),
    INDEX documents_reminder (tenant_id, remind_at),
    UNIQUE KEY documents_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_files (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(32) NOT NULL, -- original, ocr_text, ai_source
    storage_key VARCHAR(64) NOT NULL,
    relative_path VARCHAR(768) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    sha256 CHAR(64) NULL,
    size_bytes BIGINT UNSIGNED NULL,
    FOREIGN KEY (tenant_id, document_id) REFERENCES documents(tenant_id, id) ON DELETE CASCADE,
    UNIQUE KEY document_file_role (tenant_id, document_id, role),
    INDEX files_checksum (tenant_id, sha256),
    UNIQUE KEY document_files_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS folders (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    parent_id BIGINT UNSIGNED NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id, parent_id) REFERENCES folders(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, owner_id) REFERENCES users(tenant_id, id),
    INDEX folder_parent (tenant_id, parent_id, name),
    UNIQUE KEY folders_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS folder_documents (
    tenant_id BIGINT UNSIGNED NOT NULL,
    folder_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, folder_id, document_id),
    INDEX document_folders (tenant_id, document_id, folder_id),
    FOREIGN KEY (tenant_id, folder_id) REFERENCES folders(tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id, document_id) REFERENCES documents(tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tags (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    normalized_name VARCHAR(190) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY tags_tenant_id (tenant_id, id),
    UNIQUE KEY tags_normalized_name (tenant_id, normalized_name),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_tags (
    tenant_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, document_id, tag_id),
    INDEX tag_documents (tenant_id, tag_id, document_id),
    FOREIGN KEY (tenant_id, document_id) REFERENCES documents(tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id, tag_id) REFERENCES tags(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mini-Buchhaltung: Belegwährung, Standard EUR. Keine implizite Umrechnung.
-- M2 validiert Dezimalstellen/Rundung je Währung, Summen getrennt je Währung.
-- DECIMAL(18,4) erhält 14 Vorkommastellen und bis zu 4 Währungsnachkommastellen.
-- Ein Kopf je Dokument; Ordnerzugehörigkeit vervielfacht keine Rechnung.
CREATE TABLE IF NOT EXISTS accounting_accounts (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    framework VARCHAR(100) NOT NULL DEFAULT '',
    code VARCHAR(32) NOT NULL,
    name VARCHAR(190) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY framework_account (tenant_id, framework, code),
    UNIQUE KEY accounting_accounts_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_vat_rates (
    tenant_id BIGINT UNSIGNED NOT NULL,
    rate DECIMAL(5,2),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (tenant_id, rate),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Initiale Vorlage bei späterer freigegebener Migration: 19.00 und 7.00.

CREATE TABLE IF NOT EXISTS document_invoices (
    tenant_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED,
    sender VARCHAR(255) NOT NULL,
    invoice_number VARCHAR(255) NOT NULL,
    invoice_date DATE NOT NULL,
    entry_mode VARCHAR(16) NOT NULL, -- items / totals
    account_id BIGINT UNSIGNED NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    net DECIMAL(18,4) NOT NULL,
    tax DECIMAL(18,4) NOT NULL,
    gross DECIMAL(18,4) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id, document_id) REFERENCES documents(tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id, account_id) REFERENCES accounting_accounts(tenant_id, id) ON DELETE RESTRICT,
    INDEX invoice_date (tenant_id, invoice_date, document_id),
    PRIMARY KEY (tenant_id, document_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_items (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    position_number INT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    quantity DECIMAL(16,3) NOT NULL,
    unit_net DECIMAL(18,4) NOT NULL,
    unit_gross DECIMAL(18,4) NOT NULL,
    price_basis VARCHAR(8) NOT NULL DEFAULT 'net', -- net / gross; zuletzt bearbeitet
    account_id BIGINT UNSIGNED NULL, -- NULL erbt das Konto des Rechnungskopfs
    tax_rate DECIMAL(5,2) NOT NULL,
    net DECIMAL(18,4) NOT NULL,
    tax DECIMAL(18,4) NOT NULL,
    gross DECIMAL(18,4) NOT NULL,
    UNIQUE KEY invoice_position (tenant_id, document_id, position_number),
    FOREIGN KEY (tenant_id, document_id) REFERENCES document_invoices(tenant_id, document_id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id, account_id) REFERENCES accounting_accounts(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, tax_rate) REFERENCES accounting_vat_rates(tenant_id, rate) ON DELETE RESTRICT,
    UNIQUE KEY invoice_items_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bei items aus Positionen abgeleitet; bei totals anhand des Belegs erfasst.
CREATE TABLE IF NOT EXISTS invoice_tax_totals (
    tenant_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    tax_rate DECIMAL(5,2) NOT NULL,
    tax DECIMAL(18,4) NOT NULL,
    PRIMARY KEY (tenant_id, document_id, tax_rate),
    FOREIGN KEY (tenant_id, document_id) REFERENCES document_invoices(tenant_id, document_id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id, tax_rate) REFERENCES accounting_vat_rates(tenant_id, rate) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grundfelder; o7-Buchungsfelder werden vor der Übernahme vollständig abgeglichen.
-- Buchungen sind getrennt von Rechnungspositionen; Auswertungen dürfen beide
-- Datenquellen nicht unbemerkt zu einer doppelten Summe addieren.
CREATE TABLE IF NOT EXISTS accounting_entries (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    account_code VARCHAR(32) NOT NULL,
    booking_date DATE NULL,
    receipt_date DATE NULL,
    entry_type VARCHAR(32) NOT NULL,
    description TEXT NULL,
    net DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax DECIMAL(18,4) NOT NULL DEFAULT 0,
    gross DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    status VARCHAR(32) NOT NULL,
    FOREIGN KEY (tenant_id, document_id) REFERENCES documents(tenant_id, id) ON DELETE CASCADE,
    INDEX accounting_document (tenant_id, document_id),
    INDEX accounting_account_date (tenant_id, account_code, booking_date),
    UNIQUE KEY accounting_entries_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- trash.retention_days: persönliche Tage je Benutzer/Mandant; 0/fehlend = unbegrenzt.
-- Serverjob ab M2: deleted_at + Frist des Dokumentbesitzers, niemals Browser-Löschung.
CREATE TABLE IF NOT EXISTS user_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    value_json JSON NOT NULL,
    schema_version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, user_id, setting_key),
    FOREIGN KEY (tenant_id, user_id) REFERENCES users(tenant_id, id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(100),
    value_json JSON NOT NULL,
    schema_version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id, updated_by) REFERENCES users(tenant_id, id) ON DELETE RESTRICT,
    PRIMARY KEY (tenant_id, setting_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nur nicht geheime, validierte Werte in settings/user_settings. Keine Passwörter.
CREATE TABLE IF NOT EXISTS storage_locations (
    tenant_id BIGINT UNSIGNED NOT NULL,
    storage_key VARCHAR(64),
    name VARCHAR(190) NOT NULL,
    root_path VARCHAR(768) NOT NULL,
    linux_owner VARCHAR(100) NOT NULL,
    linux_group VARCHAR(100) NOT NULL,
    file_mode CHAR(4) NOT NULL DEFAULT '0640',
    directory_mode CHAR(4) NOT NULL DEFAULT '0750',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, storage_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE document_files ADD CONSTRAINT files_storage
    FOREIGN KEY (tenant_id, storage_key) REFERENCES storage_locations(tenant_id, storage_key) ON DELETE RESTRICT;

-- Schlüssel selbst außerhalb von Webroot, Datenbank und normalem Konfigurationsexport.
-- Verschlüsselungsverfahren/KMS mit authentifizierter Verschlüsselung vor M3 festlegen.
CREATE TABLE IF NOT EXISTS source_credentials (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    ciphertext LONGBLOB NOT NULL,
    key_id VARCHAR(100) NOT NULL,
    crypto_version SMALLINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY source_credentials_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_sources (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    owner_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL, -- imap / webdav / inbound; serverseitige Allowlist
    name VARCHAR(190) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    config_json JSON NOT NULL, -- versionierte, protokollspezifische Allowlist ohne Secrets
    config_version INT UNSIGNED NOT NULL DEFAULT 1,
    credential_id BIGINT UNSIGNED NULL,
    interval_minutes INT UNSIGNED NOT NULL DEFAULT 15,
    checkpoint_json JSON NULL, -- IMAP UIDVALIDITY/UID bzw. DAV Sync-Token
    last_success_at DATETIME NULL,
    next_run_at DATETIME NULL,
    last_error_code VARCHAR(64) NULL, -- keine Serverantworten/Secrets als UI-Fehler
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id, owner_id) REFERENCES users(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, credential_id) REFERENCES source_credentials(tenant_id, id) ON DELETE RESTRICT,
    INDEX source_owner (tenant_id, owner_id, kind, enabled),
    INDEX source_due (tenant_id, enabled, next_run_at),
    UNIQUE KEY import_sources_tenant_id (tenant_id, id),
    UNIQUE KEY import_sources_credential_id (tenant_id, credential_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS background_jobs (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    owner_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    kind VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    payload_json JSON NOT NULL, -- Referenzen statt Secrets; vor Ausführung erneut autorisieren
    total_count INT UNSIGNED NOT NULL DEFAULT 0,
    completed_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_until DATETIME NULL,
    worker_token VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    FOREIGN KEY (tenant_id, owner_id) REFERENCES users(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, source_id) REFERENCES import_sources(tenant_id, id) ON DELETE RESTRICT,
    INDEX jobs_pending (tenant_id, status, available_at, lease_until),
    INDEX jobs_owner (tenant_id, owner_id, created_at),
    UNIQUE KEY background_jobs_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS source_items (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    source_id BIGINT UNSIGNED NOT NULL,
    remote_key_hash CHAR(64) NOT NULL, -- inkl. Anhang/UIDVALIDITY bzw. DAV-Version
    document_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    sha256 CHAR(64) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY source_item_identity (tenant_id, source_id, remote_key_hash),
    FOREIGN KEY (tenant_id, source_id) REFERENCES import_sources(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, document_id) REFERENCES documents(tenant_id, id) ON DELETE RESTRICT,
    UNIQUE KEY source_items_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_events (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(100) NULL,
    details_json JSON NULL, -- geprüfte, minimierte Änderungsmetadaten, niemals Secrets
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id, actor_id) REFERENCES users(tenant_id, id) ON DELETE RESTRICT,
    INDEX audit_entity (tenant_id, entity_type, entity_id, created_at),
    INDEX audit_actor (tenant_id, actor_id, created_at),
    UNIQUE KEY audit_events_tenant_id (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS migration_map (
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_system VARCHAR(32) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    source_metadata JSON NULL,
    migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, source_system, entity_type, source_id),
    INDEX migration_target (tenant_id, entity_type, target_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_attempts (
    bucket CHAR(64) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL,
    window_started DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
