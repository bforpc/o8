-- Case-insensitive tenant names, ignoring surrounding spaces; includes inactive tenants.
ALTER TABLE tenants ADD COLUMN name_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (LOWER(TRIM(name))) STORED, ADD UNIQUE KEY tenants_name_unique (name_key);
