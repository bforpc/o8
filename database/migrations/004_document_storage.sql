-- Storage identity checks and optimistic document edits.
ALTER TABLE storage_locations ADD COLUMN identity_json JSON NULL;
ALTER TABLE documents ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 1;
