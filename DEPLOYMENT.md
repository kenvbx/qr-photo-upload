# Deployment and recovery (not yet deployed)

## Deploy checklist

1. Provision one Laravel PHP 8.3+ instance (GD and PDO MySQL), MySQL 8+ and persistent private storage. Point web root exclusively to `backend/public`.
2. Run `composer install --no-dev --optimize-autoloader`. Configure `.env.production.example`, generate an application key once, and keep it stable across releases.
3. Set `APP_URL` to the HTTPS origin, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; configure least-privilege DB credentials outside Git. Set private directory permissions for the PHP service account. Do not create a public storage symlink for photos.
4. Set PHP `upload_max_filesize=10M`, `post_max_size=110M`, `max_file_uploads=10`, `memory_limit=256M` or higher; proxy body limit at least 110 MB and a suitable bounded upload timeout. Laravel validation still enforces limits.
5. Back up before migrations; run `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`. Create an admin interactively with `php artisan admin:create organizer@your-domain`.
6. Probe `/up`, then sign in, create a collection, scan a real phone on HTTPS, upload JPEG/PNG/WebP, confirm guest cannot browse images, download/delete, close the session, and verify an expired link rejects uploads.
7. Run MySQL feature tests and a parallel upload/close/quota test against a disposable database before claiming concurrent correctness. SQLite tests cannot prove InnoDB row locking. Do not point automated tests at a live database.

## Operations

Monitor application errors, 5xx responses, upload failures/latency and disk usage. Audit events are stored in `audit_events` with action and resource IDs. Proxy access logs must redact `/u/{token}` bearer URLs; do not collect uploaded bytes or passwords. Rate limits are independent for login, upload-page viewing, uploads and collection creation. Configure HTTPS/HSTS at the reverse proxy and trust only actual proxies.

Session expiry does not delete photos. Set a retention and storage quota policy before opening a public service; the current app needs manual deletion. Limit which hosts can reach a demo login.

## Backup / restore / rollback

Take a consistent snapshot of MySQL plus `storage/app/private` and preserve the application key separately. During restore, put the app in maintenance mode, restore the matching DB/files pair, set private permissions, clear caches, and smoke-test owner downloads and blocked guest gallery access. Test a restore before promising recovery objectives. No automated backup or restore drill is provided in this MVP.

For rollback, retain the previous code release and a pre-migration snapshot. Restore code compatible with the current schema; if it is incompatible, restore the matching DB/files snapshot during maintenance. Avoid automatically running destructive down-migrations. A crash during upload/deletion can leave DB/file mismatches: reconcile against metadata, quarantine orphan files, and restore missing files from backup. No RPO/RTO guarantees are claimed.
