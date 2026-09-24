-- Inbound KI values may be incomplete. NULL means unknown, not zero.
ALTER TABLE document_invoices MODIFY invoice_date DATE NULL;
ALTER TABLE document_invoices MODIFY net DECIMAL(18,4) NULL;
ALTER TABLE document_invoices MODIFY tax DECIMAL(18,4) NULL;
ALTER TABLE document_invoices MODIFY gross DECIMAL(18,4) NULL;
