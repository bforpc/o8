-- M2.6: indexes for tenant-/owner-scoped document lists and structured detail filters.
ALTER TABLE documents ADD INDEX documents_search_latest (tenant_id, deleted_at, in_inbox, expired, searchable, created_at, id);
ALTER TABLE documents ADD INDEX documents_owner_search_latest (tenant_id, owner_id, deleted_at, in_inbox, expired, searchable, created_at, id);
ALTER TABLE document_invoices ADD INDEX invoice_gross_document (tenant_id, gross, document_id);
ALTER TABLE accounting_accounts ADD INDEX accounting_account_code (tenant_id, code, id);
ALTER TABLE invoice_items ADD INDEX invoice_item_account_document (tenant_id, account_id, document_id);
