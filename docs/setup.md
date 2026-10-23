# Reproduce U-Mail locally

Use PHP 8.4.1+, Composer 2, Node.js 22 and npm. Enable mbstring, openssl, fileinfo, dom, curl, zip, pdo_sqlite/sqlite3 for this walkthrough (pdo_mysql for MySQL). On Windows, verify `php -v` points at the intended PHP version. Avoid old unzip executables and deeply nested paths; use a short checkout path or PHP ZipArchive/current 7-Zip if Composer extraction reports truncated paths.

```powershell
git clone https://github.com/amenallah5577/u-mail.git
cd u-mail
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File database/database.sqlite
```

Set these values in the new local `.env` before migration:

```dotenv
APP_URL=http://127.0.0.1:8090
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/u-mail/database/database.sqlite
SESSION_SECURE_COOKIE=false
MAIL_MAILER=log
AI_FEATURES_ENABLED=false
```

Use your actual absolute SQLite path (forward slashes work on Windows). The secure-cookie exception is for loopback HTTP development only; retain secure cookies and HTTPS in deployment. `MAIL_MAILER=log` writes account notifications to `storage/logs/laravel.log` and never sends real emails. Keep that file private. For a visual mail catcher, start the included Mailpit compose file and use the example SMTP values instead.

```powershell
php artisan migrate
php artisan utica:create-admin admin@example.test --name="Demo Administrator"
npm run build
php artisan test
php artisan serve --host=127.0.0.1 --port=8090
```

The admin command prompts for a password; use at least 12 characters with upper/lower case, digit and symbol. Open `/utica-admin-entry` for admin login and `/register` for employee onboarding. Only a fresh local database should use synthetic demonstration accounts.

Run these in separate terminals for queued delivery, agent jobs and scheduled work:

```powershell
php artisan queue:work --queue=critical-mail,external-mail,default --tries=5
php artisan schedule:work
```

For a MySQL setup, create an empty database and scoped application user, configure DB_HOST/PORT/DATABASE/USERNAME/PASSWORD and DB_CONNECTION=mysql, then run the same migrations. Schema creation is entirely migration-driven. Back up the database and `storage/app/private` together. The automated suite uses its own in-memory SQLite database and array mailer; no SMTP provider, Ollama model or production database is needed.

For local AI, install Ollama, pull `llama3.2`, configure `AI_PROVIDER=local`, `AI_LOCAL_ENDPOINT=http://127.0.0.1:11434` and enable AI according to the settings UI/pilot instructions. Authorization tests use controlled model responses; passing them does not measure model quality. Keep Ollama host-local and run its worker through the named queues above.

## Verification record

23 October 2026: installed locked Composer/npm dependencies from a separate clean clone; built frontend assets; migrated a fresh SQLite database including removal of the credential registry. The existing automated suite runs against isolated SQLite. A legacy Oracle unzip on this Windows machine caused long-path extraction warnings; excluding it from the install command's PATH allowed Composer to finish. This procedure does not verify production MySQL, external SMTP or an internet deployment.
