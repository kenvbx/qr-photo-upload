# Deployment and recovery (not yet deployed)

## Deploy checklist

1. Provision one Laravel PHP 8.3+ instance (GD, Phar, optional EXIF for JPEG orientation, and PDO MySQL), MySQL 8+ and persistent private storage. Point web root exclusively to `backend/public`.
2. Run `composer install --no-dev --optimize-autoloader`. Configure `.env.production.example`, generate an application key once, and keep it stable across releases.
3. Set `APP_URL` to the HTTPS origin, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; configure least-privilege DB credentials outside Git. Set private directory permissions for the PHP service account. Do not create a public storage symlink for photos.
4. Set PHP `upload_max_filesize=10M`, `post_max_size=110M`, `max_file_uploads=10`, `memory_limit=256M` or higher; proxy body limit at least 110 MB and a suitable bounded upload timeout. Laravel validation still enforces limits.
5. Back up before migrations; run `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`. Create an admin interactively with `php artisan admin:create organizer@your-domain`.
6. Probe `/up`, then sign in, create a collection, scan a real phone on HTTPS, upload JPEG/PNG/WebP, confirm guest cannot browse images, download/delete, close the session, and verify an expired link rejects uploads.
7. Confirm the dedicated MySQL CI job passes the full suite, including parallel replay and final-slot quota races. Run a parallel upload/close smoke check against a disposable staging database before production. SQLite tests cannot prove InnoDB row locking. Do not point automated tests at a live database.

## Operations

Monitor application errors, 5xx responses, upload failures/latency and disk usage. Audit events are stored in `audit_events` with action and resource IDs. Proxy access logs must redact `/u/{token}` bearer URLs; do not collect uploaded bytes or passwords. Rate limits are independent for login, upload-page viewing, uploads and collection creation. Configure HTTPS/HSTS at the reverse proxy and trust only actual proxies.

Session expiry does not delete photos. Set a retention and storage quota policy before opening a public service; the current app needs manual deletion. Limit which hosts can reach a demo login.

## Backup / restore / rollback

Take a consistent snapshot of MySQL plus `storage/app/private` and preserve the application key separately. During restore, put the app in maintenance mode, restore the matching DB/files pair, set private permissions, clear caches, and smoke-test owner downloads and blocked guest gallery access. Test a restore before promising recovery objectives. No automated backup or restore drill is provided in this MVP.

For rollback, retain the previous code release and a pre-migration snapshot. Restore code compatible with the current schema; if it is incompatible, restore the matching DB/files snapshot during maintenance. Avoid automatically running destructive down-migrations. A crash during upload/deletion can leave DB/file mismatches: reconcile against metadata, quarantine orphan files, and restore missing files from backup. No RPO/RTO guarantees are claimed.

## Reliability upgrade

Back up the database/private disk before `php artisan migrate --force`. This release adds unique photo content hashes and upload receipts without removing existing data. Readable old photos are hashed lazily during the next active upload; first-use latency may rise with the old collection size. Existing duplicate files are preserved. Do not prune receipts independently while a collection may still need retries: receipt retention is what prevents a completed request from resurrecting an admin-deleted photo. Native forms and JSON clients both support upload IDs; JSON clients require one. Do not log photo content or bearer URLs when diagnosing network failures.

Smoke test real transfer progress, retry after loss of a response following commit, identical-byte duplicate selection, retry cooldown, expired CSRF, closure, and confirmation counts. Browser retries are per whole file and limited automatically; do not add proxy retries for non-idempotent uploads that omit an upload key. Keep retention/reconciliation and the separate private-file backup plan in place.

## Gallery selection smoke check

Open a private collection, browse the lightbox with keyboard and touch controls, select several photos, download the ZIP and compare original bytes. Cancel then confirm deletion, verify unselected photos remain and confirm another owner/guest cannot use selection endpoints. Test missing-file feedback and the native form without JavaScript. Keep OS temporary storage private and allow capacity for up to 240 MB of photo data per ZIP request; archives are deleted after normal sending or build errors. A process crash can leave `gather-*.zip` files in the PHP temp directory; establish an age-based cleanup policy before public use. Selection actions have a separate 20 requests/owner/minute rate limit.

## Thumbnail worker release and operations

1. Back up DB/private storage and deploy the additive thumbnail migration before new code handles uploads. Keep `QUEUE_CONNECTION=database`; thumbnail jobs explicitly target the database connection, whose jobs table must remain on the same default DB connection as photos. Retain the existing jobs/failed_jobs migrations.
2. Run `php artisan photos:thumbnails` once for existing pending photos. This only queues work. A retry/repair run can use `--collection=ID --retry-failed`. No original is rewritten.
3. Supervise `php -d memory_limit=256M artisan queue:work database --queue=thumbnails --sleep=1 --tries=3 --timeout=60 --memory=256` from `backend`, using the same environment/private storage and PHP modules as web requests. Configure automatic worker restart and at least 90 seconds graceful stop time. Keep retry_after above timeout (default 90 > 60 seconds); do not run thumbnail workers against a separate jobs DB.
4. After releases/config changes, use `php artisan queue:restart` and confirm the supervisor launches a worker on the new code. Restart signals are cache-backed; keep the configured database cache available to web and workers.
5. Monitor thumbnails queue age/count, pending/failed photo counts and `php artisan queue:failed`. Fix disk/source errors, then retry a failed UUID with `queue:retry UUID` or requeue failed photos with the scoped backfill command. Worker processes should have at least 256 MB PHP memory, writable persistent private storage and GD JPEG/PNG/WebP support. Enable PHP EXIF for orientation-aware previews.
6. Smoke test pending placeholders, ready JPEGs, byte-identical original downloads, retries after storage failure, failed jobs, old-photo backfill and deletion while queued. Guests/other owners must be unable to fetch thumbnails. Refresh the gallery to update readiness. Original acceptance does not imply preview completion.

Normal render failures remove partial files; crashes can leave `.tmp`/unpublished thumbnails. Backfill overwrites incomplete derivatives and repairs missing ready files; deletion removes deterministic final/temporary paths under the worker's photo lock. Reconcile after crashes and retain consistent database/private-storage backups. Stopping the worker leaves safe pending placeholders and usable originals. A server rollback must stop/restart thumbnail workers before restoring code/schema; do not run new jobs against an old schema.
