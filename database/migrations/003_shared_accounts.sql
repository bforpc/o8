-- Global authentication, existing users become tenant memberships with stable IDs.
CREATE TABLE IF NOT EXISTS accounts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    login VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    email VARCHAR(254) NOT NULL,
    email_normalized VARCHAR(254) NOT NULL,
    email_verified_at DATETIME NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY accounts_login (login),
    UNIQUE KEY accounts_email (email_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS account_identifiers (
    identifier VARCHAR(254) COLLATE utf8mb4_unicode_ci PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE users ADD COLUMN account_id BIGINT UNSIGNED NULL, ADD UNIQUE KEY users_account (tenant_id,account_id), ADD CONSTRAINT users_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;
INSERT INTO accounts (id,login,display_name,email,email_normalized,email_verified_at,password_hash,must_change_password,active,auth_version,created_at,last_login_at)
SELECT u.id,LOWER(TRIM(u.login)),u.display_name,u.email,LOWER(TRIM(u.email_normalized)),u.email_verified_at,u.password_hash,u.must_change_password,1,u.auth_version,u.created_at,u.last_login_at
FROM users u LEFT JOIN accounts a ON a.id=u.id WHERE a.id IS NULL;
INSERT INTO account_identifiers (identifier,account_id)
SELECT source.identifier,source.account_id FROM (
    SELECT LOWER(TRIM(login)) AS identifier,id AS account_id FROM accounts
    UNION SELECT LOWER(TRIM(email_normalized)),id FROM accounts
) source LEFT JOIN account_identifiers existing ON existing.identifier=source.identifier WHERE existing.identifier IS NULL;
UPDATE users SET account_id=id WHERE account_id IS NULL;
CREATE TABLE IF NOT EXISTS tenant_invitations (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    creator_version INT UNSIGNED NOT NULL,
    role VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,created_by) REFERENCES users(tenant_id,id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,role) REFERENCES roles(tenant_id,code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE users MODIFY account_id BIGINT UNSIGNED NOT NULL, DROP INDEX users_login, DROP INDEX users_email_normalized, DROP COLUMN login, DROP COLUMN email, DROP COLUMN email_normalized, DROP COLUMN email_verified_at, DROP COLUMN password_hash, DROP COLUMN must_change_password, DROP COLUMN last_login_at;
