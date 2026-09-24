-- M4.1: Indexes for booking evaluations
-- Improves performance for date-range queries and account-based grouping

-- Index for document_invoices by date and account
ALTER TABLE document_invoices ADD INDEX invoice_date_account (tenant_id, invoice_date, account_id, currency);

-- Index for documents by owner and date (for evaluation filters)
ALTER TABLE documents ADD INDEX documents_owner_date_eval (tenant_id, owner_id, deleted_at, document_date, id);

-- Index for document_tags by tag (for tag-based filtering in evaluations)
ALTER TABLE document_tags ADD INDEX tag_documents_lookup (tenant_id, tag_id, document_id);