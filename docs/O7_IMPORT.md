# One-time o7 to o8 migration

This is an administrative, one-time command-line tool for the existing `silentsun` tenant. It is intentionally separate from the o8 web application, installation and upgrade paths. It has no UI and no o7 dependency in normal o8 runtime code. Archive or remove `bin/o7-import.php` and `tools/O7Import.php` after the migration has been accepted.

## Preconditions

- o8 is installed, current and has exactly one active tenant named `silentsun`.
- The tenant has exactly one active, ready membership whose account login is `jn`. Every imported document receives that membership as owner.
- The tenant storage assignment has passed o8's normal path and functional write checks. It may use the explicit SMB/NAS compatibility mode when that mount cannot preserve Unix owner/group/mode attributes.
- The local o7 source is available at `../o7` relative to o8, including `config/app.config.php`, its database and all source files. Use `--source-root=/absolute/path/to/o7` or `O7_IMPORT_SOURCE=/absolute/path/to/o7` for another location.
- No queued or running o8 background jobs exist for `silentsun`; stop interactive work and workers for this tenant while the import runs.
- The storage filesystem has enough free space for the imported document files. The tool verifies source files, hashes, file type, the regular o8 limit of 1 GiB per original, storage and target identities before it begins a reset.

## Mandatory dry-run

Run this first. It only reads o7/o8 data and files; it creates no directory, database row or document.

```sh
php bin/o7-import.php --dry-run
```

The output identifies the target tenant and owner, reports source and target counts, file-type distribution, date/tag statistics, missing/unsupported files, metadata issues, planned folder counts and every blocking condition. Any blocking condition means the destructive command will refuse to start.

## Destructive import

Only this exact two-part invocation can remove existing `silentsun` document data:

```sh
php bin/o7-import.php --reset-and-import --confirm=RESET-SILENTSUN-AND-IMPORT-O7
```

The command intentionally creates no database dump, file backup or rollback snapshot. The exact confirmation phrase remains mandatory. Before the reset it repeats the complete preflight and temporarily renames existing tenant document files only to ensure the database reset cannot leave references to files; it creates no copy and removes those temporary names immediately after the reset.

The destructive command is therefore irreversible after the reset. If an import error occurs after that point, the tool reports the failure clearly but does not attempt automatic recovery. Run the dry-run, review its output and make any independently desired backups before using the destructive command.

## Reset scope

Only data belonging to `silentsun` is reset:

- documents, managed document files, document tags, folder links, folders, document invoice data, invoice items/taxes, accounting entries and document migration mappings;
- document/folder/tag audit rows;
- source-item document references, because the referenced documents no longer exist.

The tenant, accounts/users, roles, storage assignment, accounting account catalogue, VAT rates, source configuration, AI configuration, user appearance and column preferences remain unchanged. The reset refuses to run if a preserved source configuration still refers to a tag that would be removed.

Pending inbound items are not documents and are left unchanged.

## Folder and date rules

Exactly these folders are created; `Steuer` is a child of `FeWo`:

```text
2026
FeWo
FeWo/Steuer
Steuer Jan
Steuer CC
Bank
Verträge
Behörden
KFZ
Haus
x-2025
```

Only the o7 `dms.date_document` value controls automatic links:

- `<= 2025-12-31` goes only to `x-2025`.
- `>= 2026-01-01` goes to `2026`.
- missing or invalid document dates go only to `x-2025`.
- a case-insensitive `Fewosteuer` tag adds `FeWo/Steuer` only for a valid 2026 document date. Such a document has exactly `2026` and `FeWo/Steuer`; no other automatic links are created.

## Tags and metadata

Tags are copied from the o7 document tag references, not blindly from o7 AI suggestions. Names are deduplicated case-insensitively and the first encountered source spelling is retained. The tool reuses o8's safe ingest for PDF/JPEG/PNG/ODT/TXT originals and uses regular o8 document metadata, tag, folder and partial-invoice operations where applicable. Other binary or unknown source types are deliberately skipped, listed in the dry-run and final report, and do not bypass validation.

The o7 title, note, document/reminder date, searchable/expired flags, original filename, sender/reference/type where clearly available in valid o7 AI JSON, and OCR text up to o8's 1 MiB sidecar limit are transferred. Raw o7 AI JSON, master relationships, translated text and non-equivalent accounting fields are not introduced into o8. An o7 document with more than one accounting row has no booking record guessed; it is reported as skipped booking data while the document itself is still imported.

## Validation after import

The command checks all imported documents for tenant and owner, original-file presence/hash, exact expected folder links, tags, expected folder structure, case-insensitive tag duplicates and migration mappings. It reports document/tag counts, 2025/2026/Fewosteuer totals and warnings before returning success.
