# Install o8

This guide is for a new, independent o8 installation. Do not use an existing application database or document directory. The PHP development router is for local testing only.

## Requirements

- PHP 8.2 or newer with PDO MySQL, mbstring, fileinfo, POSIX, sessions and Sodium.
- MariaDB 10.6+ or MySQL 8.0+ and a dedicated, initially empty UTF-8 database. The database account needs schema-change rights for installation and upgrades.
- A web server that serves the `public/` directory, or the project directory with its `.htaccess` protections enabled. Use HTTPS for any network-accessible installation. Never expose `app/`, `bin/`, `database/`, `config.php` or `storage/` over HTTP.
- A private writable `storage/system/` directory for the installation identity and sessions. Tenant document storage is configured separately by the operator outside the project and web root.
- PHP IMAP for mailbox sources and PHP cURL for WebDAV and external AI. For PDF text/OCR, install `pdftotext`, `pdftoppm` and Tesseract with the languages you need (German uses `deu`). These integrations are optional.

The included Bootstrap assets are local. No npm installation or frontend build is required to run o8.

## New installation

1. Create an empty database and its dedicated account. Choose a unique strong database password; do not reuse the example values shown by the installer or `config.php.dist`.
2. Make `storage/system/` private and writable by the PHP/web-server account. Do not place real document storage below the web root. If the web installer should create `config.php`, the project directory must be writable by the PHP account temporarily; remove that extra write permission after setup. Alternatively copy `config.php.dist` to `config.php`, replace **all** example database credentials, and restrict the file to the PHP account (for example mode `0640`). Never commit `config.php`.
3. From the project directory run `php bin/setup.php token` on the server. Keep the printed setup code private; it expires after 60 minutes.
4. Open o8 in the browser. The installer uses the setup code and the credentials from the web form or your local `config.php`. It refuses a non-empty foreign database.
5. Use **Operator login** for the first sign-in. The initial operator account is `admin` with the one-time start password `owndms8`; enter the setup code again under the first-installation option. Change the start password immediately when prompted.
6. Complete the operator profile and create the first tenant. The tenant admin account is distinct from the operator account. Sign in normally to work with tenant documents.
7. As operator, open **Storage**, select the tenant, and validate an existing canonical base directory outside the project. Specify the Linux owner and group that should own new files. o8 creates and checks the tenant-specific directory; it does not mount a filesystem or move existing files for you.

For a local development-only run, `php -S 127.0.0.1:18088 router.php` serves the project at `http://127.0.0.1:18088/`. Do not expose this router to a network.

## Updates and backups

Before updating, back up **both** the database and all storage. Include `storage/system/installation.json`: its identity and key are required to recognize the database and decrypt saved source/AI credentials. Keep backups and private keys outside Git and the web root.

After installing new application files and migrations, run `php bin/setup.php upgrade`. Check the result with `php bin/setup.php status`. Never replace a live `config.php` with the distribution template or edit an already applied migration. Test upgrades against a separate restored copy first.

If tenant storage is relocated, move or copy the entire tenant directory, including its `.o8-storage` marker, before using the operator's storage-reassignment form. The application verifies the new path but does not move the files itself.

## Scheduled workers

Manual source fetch and manual AI analysis are available from the UI without a cron worker. Configure periodic CLI calls only for the functions you enable:

```sh
O8_SOURCE_WORKER=1 php bin/source-fetch.php work --once
O8_AI_WORKER=1 php bin/inbound-ai.php work --once
php bin/trash-retention.php
```

Run these as the appropriate application service account, from the project directory, at an interval suitable for your installation (for example every five minutes). The source worker handles scheduled IMAP/WebDAV fetches and configured automatic acceptance; the AI worker handles automatic AI jobs. The trash worker is needed only when a user's retention setting is greater than zero days. A setting of zero retains trash indefinitely. The UI reports when a required worker has not run recently.

## Security and testing

Keep `config.php`, `.env`, `storage/`, document originals, database exports, source credentials and signing keys out of the repository. The `.gitignore` covers usual local paths, but does not protect files already tracked or added with `git add -f`; review every commit before publication. Restrict file permissions, use HTTPS and back up the installation regularly.

JavaScript checks run with `node --test tests/*.test.js`. Use `.venv/bin/python` and headless Chromium for the Playwright tests. Database and full-browser tests need an isolated disposable MariaDB instance and test installation; never run them against live tenant data.
