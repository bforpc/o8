ALTER TABLE inbound_items
    ADD COLUMN acceptance_status VARCHAR(24) NULL,
    ADD COLUMN acceptance_message VARCHAR(1000) NULL,
    ADD COLUMN acceptance_attempted_at DATETIME NULL;
