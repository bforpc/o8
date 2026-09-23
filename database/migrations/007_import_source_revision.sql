ALTER TABLE import_sources ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1 AFTER config_version;
ALTER TABLE import_sources ADD UNIQUE INDEX import_source_owner_name (tenant_id, owner_id, kind, name);
