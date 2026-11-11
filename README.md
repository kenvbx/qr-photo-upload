# Gather — QR Photo Upload

**Personal / demo project.** A working Laravel 13 application for collecting photos with expiring QR links. No production clients, usage or performance results are claimed.

## What works

- Organizer login and logout; per-owner collections and private gallery.
- Create upload collections with 1-hour to 7-day expiry and immediate closure.
- Server-generated SVG QR codes and copyable guest URLs.
- Local photo previews with thumbnail, filename and size; remove individual photos before upload. File selection stays on-device until submission. Choosing files again replaces the pending selection.
- Per-photo XMLHttpRequest transfer progress, separate server-confirmation state, one automatic retry for transient failures, and manual retry of failed photos only. Accepted photos are never retransmitted by the retry controls.
- Idempotent upload receipts and SHA-256 deduplication of identical bytes within a collection, including renamed files; duplicate photos do not consume quota.
- Multiple JPEG/PNG/WebP uploads: 10 MB each, 10 per request, 100 per collection; actual MIME, image decoding and pixel bounds (20 MP / 12,000 pixels per side).
- Random private file paths; authorized image display, original download and deletion.
- Server-side ownership, CSRF, named rate limits, audit events and upload failure compensation.
- Responsive English UI; no guest registration, external fonts or frontend build needed.

## Local setup

Requires PHP 8.3+ with GD, fileinfo, XML, mbstring, SQLite or MySQL PDO support; Composer 2. Node 20+ is needed only for browser tests. Keep at least 256 MB PHP memory available for bounded image decoding.

```sh
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Create backend/database/database.sqlite if it does not exist.
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan admin:create you@example.com
php -d upload_max_filesize=10M -d post_max_size=110M -d memory_limit=256M artisan serve --host=127.0.0.1 --port=8038
```

The admin command asks for a password of at least 12 characters; no public registration exists. Open http://127.0.0.1:8038. `.env.example` uses SQLite for reproducible local setup. SQLite is the tested database so far; MySQL is the intended deployment database.

For disposable local preview only, `php artisan demo:seed` creates `organizer@example.test` with password `local-demo-photos-2026`. The command refuses to run outside the local environment and does not replace existing accounts. Never use this account on a publicly accessible deployment.

### Scan on a phone

A QR URL containing `127.0.0.1` points to the phone itself. Set `APP_URL` to your computer's reachable LAN address and port, run the dev server with `--host=0.0.0.0`, and connect the phone to the same network. For public use, deploy behind HTTPS and set `SESSION_SECURE_COOKIE=true`. LAN HTTP preview has lower security and is for disposable demo photos only. Physical-phone testing has not yet been recorded; browser mobile viewports were verified.

## Verify

```sh
cd backend
php artisan test --no-coverage
vendor/bin/pint --test
cd ..
npm ci
npx playwright install chromium
npm run test:e2e
```

Verified locally on 2026-11-11: 28 passing PHP tests / 188 assertions on SQLite (2 MySQL-only concurrency tests skipped) and 9 Chromium tests. The MySQL CI job runs the full backend suite, including concurrent same-ID uploads and parallel requests competing for the last available slot. Browser tests decode the rendered QR and use its real URL, upload bytes over HTTP, check private gallery access, original download, delete, closure, CSRF rejection and mobile layout. Preview tests cover keyboard removal, unchanged original bytes for retained photos, no upload before submission, invalid files and selection recovery. Fixtures use a separate `e2e.sqlite` database and private test storage. Reliability tests transfer real bytes, hold a real HTTP response after receipt, simulate connection loss, retry the original upload ID, verify no duplicate gallery entries, and exercise cooldown, validation, and the native form without JavaScript. Screenshots are generated under `screenshots/`; the gallery screenshot contains a small synthetic test image.

## Documentation

- [Product brief](PRODUCT_BRIEF.md)
- [Acceptance criteria](REQUIREMENTS.md)
- [Architecture and tradeoffs](ARCHITECTURE.md)
- [Design system](design-system/MASTER.md)
- [Delivery checklist](TASKS.md)
- [Deployment and recovery](DEPLOYMENT.md)

## Known limits

No public deployment has been completed. MySQL concurrency verification is covered by the dedicated CI job; see its latest result before claiming deployment readiness. This is a synchronous single-instance MVP with manual gallery refresh. No thumbnails/queue, malware scanner, EXIF stripping or automatic retention cleanup is implemented. Retries use the same upload ID; choosing the same exact file bytes again with a new ID also skips duplicates within that collection. Edited or re-encoded images have different hashes. Original photos may contain GPS metadata. Database and storage are not atomic; compensation handles normal errors, while process crashes can leave orphan files. PHP/proxy request limits must match application limits. HEIC, SVG, GIF and video are unsupported. Collection expiry stops contributions; owner access remains until individual photos are deleted.

## Upload/retry contract and upgrading

Existing checkouts must run `cd backend && php artisan migrate --force` after taking a backup. The added migration is additive: nullable photo hashes plus an upload receipt table. Existing valid files are lazily hashed under the collection lock on the next upload; this may add I/O latency on the first request. Historical duplicate rows/files are preserved. Missing legacy files are skipped without deleting metadata.

Enhanced uploads send one photo at a time to `POST /u/{token}`, with `Accept: application/json`, the page's CSRF token and multipart `upload_key` (UUID), `photos[]` (one file). The same key and ordered content fingerprint replay the original result, even after closure/expiry; a different payload using that key returns 409. New uploads after closure return 410. The response is `{data:{uploaded,duplicates,replayed}}`; it never exposes another guest's gallery or photo IDs. Native forms carry a batch upload ID too. Legacy native clients without a key remain compatible and receive content deduplication, but cannot replay a stored receipt.

Transfers time out after 180 seconds. Network errors, lost/invalid confirmation and 5xx responses get one automatic retry after 750 ms, then an explicit Retry action. 429 respects Retry-After before manual retry becomes available. Validation failures require removing/replacing the rejected photo; closed links or expired CSRF stop the pending queue. The upload limiter permits 60 requests per IP/minute to accommodate per-photo requests and bounded retries. A progress bar reaching 100% means bytes were sent; success requires a valid server acknowledgement. Overall progress stays below 100% until all photos are confirmed.

Retry state and file access live on the current page. Reloading requires selecting files again; content deduplication still prevents extra copies. This feature retries whole failed files; it does not resume chunks. Completed receipts are retained with their collection and survive individual photo deletion, so an old retry never resurrects a deleted image. A deliberate new request after deletion may upload it again. Receipts and storage still need an explicit retention policy before public service.
