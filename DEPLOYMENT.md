# Deployment and recovery (not yet deployed)

## Deploy checklist

1. Provision one Laravel PHP 8.3+ instance (GD, Phar, optional EXIF for JPEG orientation, and PDO MySQL), MySQL 8+ and persistent private storage. Point web root exclusively to `backend/public`.
2. Run `composer install --no-dev --optimize-autoloader`. Configure `.env.production.example`, generate an application key once, and keep it stable across releases.
3. Set `APP_URL` to the HTTPS origin, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; configure least-privilege DB credentials outside Git. Set private directory permissions for the PHP service account. Do not create a public storage symlink for photos.
4. Set PHP `upload_max_filesize=10M`, `post_max_size=110M`, `max_file_uploads=10`, `memory_limit=256M` or higher; proxy body limit at least 110 MB and a suitable bounded upload timeout. Laravel validation still enforces limits.
5. Back up before migrations; run `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`. Create an admin interactively with `php artisan admin:create organizer@your-domain`.
6. Probe `/up`, then sign in, create a collection, scan a real phone on HTTPS, upload JPEG/PNG/WebP, confirm guest cannot browse originals or unapproved images, download/delete, close the session, and verify an expired link rejects uploads.
7. Confirm the dedicated MySQL CI job passes the full suite, including parallel replay and final-slot quota races. Run a parallel upload/close smoke check against a disposable staging database before production. SQLite tests cannot prove InnoDB row locking. Do not point automated tests at a live database.

## Operations

Monitor application errors, 5xx responses, upload failures/latency and disk usage. Audit events are stored in `audit_events` with action and resource IDs. Proxy access logs must redact `/u/{token}` bearer URLs; do not collect uploaded bytes or passwords. Rate limits are independent for login, upload-page viewing, uploads and collection creation. Configure HTTPS/HSTS at the reverse proxy and trust only actual proxies.

Session expiry does not delete photos. Set a retention and storage quota policy before opening a public service; use the collection quota and optional future-upload retention controls, and run the scheduler. Limit which hosts can reach a demo login.

## Backup / restore / rollback

Take a consistent snapshot of MySQL plus `storage/app/private` and preserve the application key separately. During restore, put the app in maintenance mode, restore the matching DB/files pair, set private permissions, clear caches, and smoke-test owner downloads and blocked guest gallery access. Test a restore before promising recovery objectives. No automated backup or restore drill is provided in this MVP.

For rollback, retain the previous code release and a pre-migration snapshot. Restore code compatible with the current schema; if it is incompatible, restore the matching DB/files snapshot during maintenance. Avoid automatically running destructive down-migrations. A crash during upload/deletion can leave DB/file mismatches: reconcile against metadata, quarantine orphan files, and restore missing files from backup. No RPO/RTO guarantees are claimed.

## Reliability upgrade

Back up the database/private disk before `php artisan migrate --force`. This release adds unique photo content hashes and upload receipts without removing existing data. Readable old photos are hashed lazily during the next active upload; first-use latency may rise with the old collection size. Existing duplicate files are preserved. Do not prune receipts independently while a collection may still need retries: receipt retention is what prevents a completed request from resurrecting an admin-deleted photo. Native forms and JSON clients both support upload IDs; JSON clients require one. Do not log photo content or bearer URLs when diagnosing network failures.

Smoke test real transfer progress, retry after loss of a response following commit, identical-byte duplicate selection, retry cooldown, expired CSRF, closure, and confirmation counts. Whole-file retries remain the fallback, with optional resumable 512 KiB transfers; do not add proxy retries for non-idempotent uploads that omit an upload key. Keep retention/reconciliation and the separate private-file backup plan in place.

## Gallery selection smoke check

Open a private collection, browse the lightbox with keyboard and touch controls, select several photos, download the ZIP and compare original bytes. Cancel then confirm deletion, verify unselected photos remain and confirm another owner/guest cannot use selection endpoints. Test missing-file feedback and the native form without JavaScript. Keep OS temporary storage private and allow capacity for up to 240 MB per retained ZIP plus staging capacity; normal build errors remove partial archives, while completed archives are retained up to 24 hours. Legacy releases may have left `gather-*.zip` files in the PHP temp directory; the current queue uses deterministic private exports paths and hourly expiry cleanup. Selection actions have a separate 20 requests/owner/minute rate limit.

## Thumbnail worker release and operations

1. Back up DB/private storage and deploy the additive thumbnail migration before new code handles uploads. Keep `QUEUE_CONNECTION=database`; thumbnail jobs explicitly target the database connection, whose jobs table must remain on the same default DB connection as photos. Retain the existing jobs/failed_jobs migrations.
2. Run `php artisan photos:thumbnails` once for existing pending photos. This only queues work. A retry/repair run can use `--collection=ID --retry-failed`. No original is rewritten.
3. Supervise `php -d memory_limit=256M artisan queue:work database --queue=thumbnails --sleep=1 --tries=3 --timeout=60 --memory=256` from `backend`, using the same environment/private storage and PHP modules as web requests. Configure automatic worker restart and at least 90 seconds graceful stop time. Keep retry_after above timeout (default 90 > 60 seconds); do not run thumbnail workers against a separate jobs DB.
4. After releases/config changes, use `php artisan queue:restart` and confirm the supervisor launches a worker on the new code. Restart signals are cache-backed; keep the configured database cache available to web and workers.
5. Monitor thumbnails queue age/count, pending/failed photo counts and `php artisan queue:failed`. Fix disk/source errors, then retry a failed UUID with `queue:retry UUID` or requeue failed photos with the scoped backfill command. Worker processes should have at least 256 MB PHP memory, writable persistent private storage and GD JPEG/PNG/WebP support. Enable PHP EXIF for orientation-aware previews.
6. Smoke test pending placeholders, ready JPEGs, byte-identical original downloads, retries after storage failure, failed jobs, old-photo backfill and deletion while queued. Guests/other owners must be unable to fetch owner thumbnail routes; separate album routes allow only approved ready previews while sharing is enabled. The gallery updates readiness automatically; manual refresh remains available. Original acceptance does not imply preview completion.

Normal render failures remove partial files; crashes can leave `.tmp`/unpublished thumbnails. Backfill overwrites incomplete derivatives and repairs missing ready files; deletion removes deterministic final/temporary paths under the worker's photo lock. Reconcile after crashes and retain consistent database/private-storage backups. Stopping the worker leaves safe pending placeholders and usable originals. A server rollback must stop/restart thumbnail workers before restoring code/schema; do not run new jobs against an old schema.

## ZIP queue release and operations

Deploy the photo_exports migration before new code receives selection requests; back up first. Supervise a separate `php -d memory_limit=256M artisan queue:work database --queue=exports --sleep=1 --tries=3 --timeout=60 --memory=256` process from backend, sharing the default DB and private disk with web/thumbnail workers. Restart workers on releases; keep retry_after (90 seconds) greater than timeout (60 seconds) and supervisor graceful-stop time at least 90 seconds. No new package is required; PHP Phar remains necessary.

Run Laravel's scheduler each minute (`php artisan schedule:run`) so the hourly `exports:prune` definition executes, or run that command manually. This repository does not install system cron/supervisor. Monitor exports queue age/count, failed_jobs, export statuses and private disk capacity. Expiry blocks download after 24 hours even without cleanup. Archive retention is bounded only when cleanup runs; originals follow their optional per-photo retention deadlines when photos:prune runs.

Smoke test submit → queued/building → ready → original ZIP download, repeat requests for identical selections, retry after storage failure, expired/missing ZIP rebuild, source deletion revocation, pruning and late jobs. Verify guest/other-owner rejection for status/download/retry, and no-JavaScript manual refresh. Budget retained archives and staging files separately. A deleted source's private ZIP snapshot remains until expiry cleanup, but download routes refuse it immediately. Stop exports workers before restoring older schema/code.


## Collection controls and S3 / R2

Deploy the additive collection-controls migration, then restart web/queue processes. Existing photos get disk=local, existing collections get 1000 MiB quota and no automatic deletion. A backup must include each referenced object store plus the private local ZIP disk and matching database.

For Amazon S3 set `PRIVATE_PHOTO_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, and `AWS_BUCKET`. Leave AWS_ENDPOINT empty for standard AWS. For R2 set `PRIVATE_PHOTO_DISK=r2`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, and `R2_ENDPOINT=https://ACCOUNT_ID.r2.cloudflarestorage.com`. Region is auto and path-style access is enabled for R2. Keep buckets private, block public access, and grant only required object read/write/delete/list permissions for the bucket. Credentials stay in environment/secrets, never in Git. The official Flysystem AWS S3 v3 adapter is installed. SDK requests have 5-second connect and 20-second request timeouts, with one SDK retry.

Changing PRIVATE_PHOTO_DISK affects new uploads only. Do not remove old disk credentials/files while metadata still references them; no automatic migration of old files is performed. Browser downloads go through owner-authenticated routes; no public bucket URLs or browser credentials are used, so browser bucket CORS is unnecessary. ZIP jobs stream remote sources into a private per-export staging directory and clean it on success/failure; expiry pruning also removes abandoned staging after crashes. Budget up to 240 MiB source staging plus ZIP staging/archive per export. All nodes/workers must share the database and persistent local exports disk, or run on the same host.

Run Laravel scheduler every minute (`php artisan schedule:run`); hourly `photos:prune` and `exports:prune` then clean due files. This repository does not install system cron. Photo retention begins at upload time; changing settings affects future uploads only. Cleanup failures retain metadata and report errors for retry. Photo quota covers originals only; monitor thumbnail/ZIP/object-store and local capacity separately. Deleted originals can remain inside an inaccessible private ZIP until ZIP expiry pruning.

Before enabling real cloud traffic, configure a disposable private bucket and test upload → thumbnail → original download → ZIP → deletion, denied cross-owner access, unavailable bucket retry, and cleanup. Fake-disk tests verify application behavior, not actual provider credentials, bucket policy or latency. Neither S3 nor R2 has been connected in this local release. Check full-size remote ZIPs against the worker timeout before production and adjust worker timeout/retry_after together if needed.


## Resumable transfer, trash and publication release

Back up database and all photo disks plus local private branding/staging/exports before the additive migration. Existing photos receive pending review, and sharing is disabled by default. Restart long-running workers/scheduler after deployment. Photos use soft deletion for normal Delete actions; originals/derivatives remain until explicit purge, 30 days in trash or an earlier retention deadline. Quota still includes trash. Do not remove deleted rows directly in SQL, or bypass the shared removal service. Do not roll back the soft-delete migration to older code while trash exists: old code could expose it as active.

All instances need the same persistent local `resumable/`, `branding/` and `exports/` directories. Resumable staging is local even for S3/R2 originals. Budget an extra 200 MiB per collection (20 unfinished files × 10 MiB) and run hourly `uploads:prune` through the scheduler; expired rows remain private and inaccessible even if pruning is delayed. Keep proxy request limits and upload timeouts compatible with 512 KiB chunks and normal fallback/branding uploads. All chunk/init/finish/delete requests require CSRF, the upload token, current password grant when enabled, and a per-upload secret whose hash is stored server-side. Chunk endpoints are rate-limited independently at 240 requests/IP/minute. Album endpoints are limited at 300/IP/minute. Redact /a/{token}, /u/{token} and X-Upload-Secret from access/debug logs; never log uploaded bytes. Serve over HTTPS for Web Crypto/persistent resume on mobile; localhost is only a development exception.

Smoke test interrupted chunks → reload → recover/retry, lost final response, password change during transfer, closed link, expiry cleanup, low quota and two-tab retries. Test trash → hidden owner/public/ZIP access → restore for review → approve → publish; test stale purge/prune after restoration. Enable a disposable shared album, check media/status/logo authorization, pause/keyboard/mobile/fullscreen behavior and immediate denial after rotation/disable/expiry. Public previews are 480 px JPEGs; high-resolution slideshow derivatives are a future enhancement. Shared album URLs are bearer links independent of upload passwords; treat enabling them as publication to anyone holding the link. No public deployment or live cloud verification is claimed.
