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
- Database-queued JPEG gallery thumbnails (maximum 480 px, no upscaling), bounded retries and backfill for existing photos. Gallery placeholders keep originals available while processing; lightbox, download and ZIP use the originals.
- Gallery lightbox with previous/next, arrow keys, Escape, focus containment, loading/error feedback and selection inside the viewer.
- Select individual photos or the current page (up to 24), clear selection, queue a private ZIP of originals with a status/download page, or confirm moving photos to trash. Selection is local to the current gallery page.
- Server-side ownership, CSRF, named rate limits, audit events and upload failure compensation.
- Responsive English UI; no guest registration, external fonts or frontend build needed.

## Local setup

Requires PHP 8.3+ with GD, Phar, fileinfo, XML, mbstring, SQLite or MySQL PDO support; Composer 2. Node 20+ is needed only for browser tests. Keep at least 256 MB PHP memory available for bounded image decoding in both web and queue processes. Enable the optional PHP EXIF extension to honor JPEG orientation in thumbnails (enabled in CI).

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

In a second terminal inside `backend`, start the thumbnail worker:

```sh
php -d memory_limit=256M artisan queue:work database --queue=thumbnails --sleep=1 --tries=3 --timeout=60 --memory=256
```

The admin command asks for a password of at least 12 characters; no public registration exists. Open http://127.0.0.1:8038. `.env.example` uses SQLite for reproducible local setup. SQLite is verified locally; MySQL 8.4 is verified in GitHub Actions, including concurrent upload replay and quota checks.

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

Verified locally on 2026-11-12: 83 passing PHP tests / 806 assertions on SQLite (6 MySQL-only concurrency tests skipped) and 20 Chromium tests. The MySQL CI job runs the full backend suite, including concurrent same-ID uploads, parallel requests competing for the last available slot, thumbnail generation racing deletion, and simultaneous identical ZIP requests. Browser tests decode the rendered QR and use its real URL, upload bytes over HTTP, check private gallery access, original download, delete, closure, CSRF rejection and mobile layout. Preview tests cover keyboard removal, unchanged original bytes for retained photos, no upload before submission, invalid files and selection recovery. Fixtures use a separate `e2e.sqlite` database and private test storage. Reliability tests transfer real bytes, hold a real HTTP response after receipt, simulate connection loss, retry the original upload ID, verify no duplicate gallery entries, and exercise cooldown, validation, and the native form without JavaScript. Gallery tests cover keyboard focus and navigation, mobile/landscape layout, synchronized selection, ZIP download, delete confirmation/cancellation, image load failure, native forms, mixed-owner rejection, partial deletion failure and stable pagination. Thumbnail tests exercise a real database worker, atomic queue insertion, failed-job exhaustion/recovery, original byte preservation, bounds, JPEG EXIF orientations, transparency, private routes, cleanup and backfill. The browser harness starts and stops a separate worker alongside the test server. Screenshots are generated under `screenshots/`; the gallery screenshot contains a small synthetic test image.

## Documentation

- [Product brief](PRODUCT_BRIEF.md)
- [Acceptance criteria](REQUIREMENTS.md)
- [Architecture and tradeoffs](ARCHITECTURE.md)
- [Design system](design-system/MASTER.md)
- [Delivery checklist](TASKS.md)
- [Deployment and recovery](DEPLOYMENT.md)

## Known limits

No public deployment has been completed. This is a single-instance MVP with synchronous original validation and asynchronous thumbnails/ZIPs. Local workers/scheduler are session processes; production still needs supervision, backups and an operational restore drill. S3/R2 integration is prepared and tested with fake remote disks, without live credentials/provider verification. No malware scanner or default original EXIF stripping is implemented. Approved public album images are re-encoded JPEG previews, while originals can still contain GPS metadata and stay owner-only. Database and file storage are not atomic across process crashes; normal failure compensation is provided, and crash reconciliation/backup remain operational responsibilities. HEIC, SVG, GIF and video are unsupported. Collection expiry stops uploads; album sharing has its own opt-in expiry.

## Upload/retry contract and upgrading

Existing checkouts must run `cd backend && php artisan migrate --force` after taking a backup. The added migration is additive: nullable photo hashes plus an upload receipt table. Existing valid files are lazily hashed under the collection lock on the next upload; this may add I/O latency on the first request. Historical duplicate rows/files are preserved. Missing legacy files are skipped without deleting metadata.

Enhanced uploads send one photo at a time to `POST /u/{token}`, with `Accept: application/json`, the page's CSRF token and multipart `upload_key` (UUID), `photos[]` (one file). The same key and ordered content fingerprint replay the original result, even after closure/expiry; a different payload using that key returns 409. New uploads after closure return 410. The response is `{data:{uploaded,duplicates,replayed}}`; it never exposes another guest's gallery or photo IDs. Native forms carry a batch upload ID too. Legacy native clients without a key remain compatible and receive content deduplication, but cannot replay a stored receipt.

Transfers time out after 180 seconds. Network errors, lost/invalid confirmation and 5xx responses get one automatic retry after 750 ms, then an explicit Retry action. 429 respects Retry-After before manual retry becomes available. Validation failures require removing/replacing the rejected photo; closed links or expired CSRF stop the pending queue. The upload limiter permits 60 requests per IP/minute to accommodate per-photo requests and bounded retries. A progress bar reaching 100% means bytes were sent; success requires a valid server acknowledgement. Overall progress stays below 100% until all photos are confirmed.

By default, retry state and file access live on the current page and retries resend whole failed files. Enable Save progress on this device for chunk resume and reload recovery, as described below. Completed receipts are retained with their collection and survive individual photo deletion, so an old retry never resurrects a deleted image. Content deduplication includes trash; a deliberate new request after permanent deletion may upload the same bytes again. Receipts and storage still need an explicit retention policy before public service.

## Gallery selection

Open a photo to browse the current page in the lightbox. Use the arrow buttons or keyboard arrows; Escape closes the viewer and returns focus to the opener. Checkboxes in the grid and viewer stay in sync. Select this page selects up to 24 displayed photos; the toolbar shows the selected count. Selection does not span pagination or persist after leaving the page. Without JavaScript, photo links open originals and the individual checkboxes and bulk form still work; the permanent-delete warning remains visible.

`POST /collections/{collection}/photos/selection` requires the owner, CSRF, an action (`download` or `delete`) and 1–24 distinct `photos[]` IDs from that collection. It permits 20 requests per owner/minute. Any foreign or stale ID rejects the whole request before work starts. ZIP requests redirect to a private status page; the database `exports` queue builds original-byte archives with safe photo-ID filenames using PHP PharData. Refresh/poll until ready, then download repeatedly for up to 24 hours. Missing/unreadable sources fail the whole build instead of returning a partial archive. Budget up to 240 MB per retained export plus building-file capacity; completed archives are retained until expiry cleanup. Recent requests are linked from the collection gallery. Leaving the selection page clears its checkboxes.

Deletion uses the same per-photo locked removal and audit behavior as individual deletion. A storage failure preserves that photo's metadata and reports how many other photos were removed; batch deletion is best effort, not atomic across files.

## Thumbnail queue and upgrading

Back up before `php artisan migrate --force`. The additive migration gives existing photos a pending thumbnail status; it does not rewrite their originals. New unique uploads enqueue one `GeneratePhotoThumbnail` job on the `database` connection and `thumbnails` queue inside the same transaction as the photo/receipt. The queue connection deliberately uses the application's default database so job insertion participates in that transaction. Do not split its jobs table into a different database/connection. Upload replay/content duplicates do not enqueue extra work. Thumbnail jobs explicitly use the database queue even if an older checkout still has `QUEUE_CONNECTION=sync`; update that value to `database` and run the worker above.

To enqueue old pending photos or repair missing derivatives:

```sh
cd backend
php artisan photos:thumbnails
# Optional scope and recovery after fixing storage/source failures:
php artisan photos:thumbnails --collection=1 --retry-failed
php artisan queue:failed
# Alternatively retry a selected failed-job UUID with queue:retry UUID.
```

The command skips ready files and, by default, exhausted failures. Repeated backfill can enqueue duplicate pending jobs; processing is safe because a ready derivative is a no-op. Jobs have 3 attempts, 10/60-second exception backoff and a 60-second timeout. Keep the database queue's `retry_after` above the timeout (default 90 seconds). Failed originals remain accessible; normal exhaustion records `failed_jobs` and marks the preview failed. Deleted photos are skipped safely, and stale failure callbacks cannot downgrade a ready preview.

The worker bounds source size/dimensions before decoding with GD, fits the image within 480 × 480 without enlargement, flattens transparency onto white, and writes JPEG at quality 82. JPEG EXIF orientation is applied when PHP EXIF is enabled; without it, stored pixel orientation is used. Originals and their metadata remain unchanged. Private thumbnail files are published from a temporary file under a per-photo mutation lock; the gallery endpoint never serves a partially written preview. The same lock serializes deletion and processing. Normal errors clean up partial derivatives. A process crash can leave a `.tmp` or unpublished derivative; rerun backfill or delete the photo after restoring healthy storage.

`GET /photos/{photo}/thumbnail` requires the collection owner and uses private/no-store responses. Pending or unavailable previews return a small static SVG placeholder rather than transferring the original. Refresh the gallery after processing. SQLite uses WAL, a 10-second busy timeout, and reserves its writer before app mutation reads (PHP 8.3), with immediate transactions where supported. Use MySQL for deployment and independently supervise the worker; no throughput claim is made for SQLite.

## Queued ZIP downloads

Run the additive migration before using the new download flow. Start a separate export worker in `backend` alongside the thumbnail worker:

```sh
php -d memory_limit=256M artisan queue:work database --queue=exports --sleep=1 --tries=3 --timeout=60 --memory=256
# Remove expired final/building ZIPs and export records:
php artisan exports:prune
```

Export metadata and its job commit together on the default application database. Identical sorted selections reuse an unexpired queued/processing/ready export, serialized by the collection lock. A build holds an export lock and locks source photos in ID order, publishes a private staging ZIP via rename, and marks ready only when complete. Workers retry exceptions at most 3 times with 10/60-second backoff and a 60-second timeout; retry_after remains 90 seconds. Exhausted requests show Retry ZIP; retrying a queued/processing/ready request does not enqueue another job. Rebuilding an expired/missing archive renews its 24-hour window if source rows still exist.

The status page automatically polls, distinguishes waiting/building/ready/failed/expired, recovers from polling errors and provides a manual Refresh status link without JavaScript. It never starts downloads automatically. Gallery lists the five most recent requests; pagination/selection still covers at most 24 photos per request. All status, retry and download routes require the owner; retry requires CSRF and shares the selection rate limit. JSON status contains no storage paths or selected photo IDs.

Expiry blocks access even if cleanup has not run. Laravel schedules `exports:prune` hourly; production must run the scheduler (`php artisan schedule:run` every minute) and supervise both queue workers. Cleanup takes the same export lock as build/retry and preserves records on ordinary storage deletion failure. After pruning, late jobs are no-ops and old URLs return 404. Source deletion revokes download access to existing ZIPs; private archive snapshots remain until pruning, and an already-started download cannot be revoked. A hard crash can leave a staging/final archive; deterministic paths allow expiry cleanup to remove it. No automatic retention is claimed for original photos.


## Collection upgrades

- Gallery refreshes every five seconds while visible. New photos and completed thumbnails appear automatically; selected IDs are kept, and replacement waits while the lightbox is open and restores focused photo controls. Search filenames/photo IDs, filter JPEG/PNG/WebP and sort by date/size. Filenames are available for new uploads; old photos remain searchable by ID.
- **Tasks** lists owner-scoped thumbnail and ZIP states, with thumbnail repair/retry and links to ZIP retry/download. Failed job details stay in operator logs. Pagination and manual refresh work without JavaScript.
- Guests may opt into browser compression: JPEG quality 82, longest side 2048 px, white transparency background. Only smaller output is used; unsupported conversion keeps the original. The exact converted File is cached for safe retries. Leave the option off for byte-identical original uploads. Converted photos may lose camera metadata.
- **Storage & access** sets each collection's original-byte quota (1–1000 MiB, default 1000) and optional retention (1–365 days). Duplicates consume no extra quota. Retention is off initially, applies only to future uploads, and records fixed deadlines; changing/disabling it does not alter existing deadlines. Hourly `photos:prune` removes due originals and thumbnails; run the scheduler or command manually. Thumbnail/ZIP bytes are additional to this quota.
- Optional QR password is hashed, rate-limited and checked on page/upload/replay. Password changes revoke previous guest grants. Share the password separately; the token and gallery remain private.
- Both **Amazon S3** and **Cloudflare R2** are supported for new originals and thumbnails. Local remains default; ZIP staging/downloads remain on private local storage. See DEPLOYMENT.md for setup. Tests exercise both configured disk names with fake remote storage that forbids local paths; no live cloud account has been connected or verified.


## Resumable uploads, trash and event albums

1. **Resume after connection loss/reload:** guests opt into **Save progress on this device**. The prepared file (including optional compression output), UUID, checksum and random per-upload secret are stored in IndexedDB. Uploads use sequential 512 KiB chunks. Reopening the same link recovers unfinished photos; click Retry failed photos to continue from the server-confirmed offset. Stored browser records expire after 24 hours and are removed on the next upload-page visit; browsers may evict them sooner. Clear saved uploads before leaving a shared device. HTTPS or localhost, IndexedDB and Web Crypto are required; native uploads and the existing whole-file retry flow remain available. No background sending occurs while the page is closed.
2. **Trash:** normal Delete/Delete selected keeps files for up to 30 days. Open trash to restore or permanently delete. An earlier photo retention deadline overrides the trash period. Photos in trash count toward both byte/count quota and content deduplication, and are excluded from galleries, new ZIPs and ready ZIP download authorization. Restored photos return to Needs review; permanent deletion and pruning release quota.
3. **Moderation:** every existing/new photo starts at Needs review. Select photos and choose Approve selected or Reject selected; filter by review status. Approval is required for public previews, and thumbnail generation must finish too. Rejection does not delete the original. Restoring a trashed photo requires fresh approval before public display.
4. **Shared album/slideshow:** enable sharing explicitly under Event appearance & album. The separate random album link expires in 1–30 days, and can be disabled or rotated. It exposes only approved JPEG previews (maximum 480 px), without original filenames/bytes/camera metadata. The album refreshes every five seconds; slideshow plays the current page (up to 24 photos) with Play/Pause, Previous/Next, arrow keys, Escape, adjustable delay and optional fullscreen. It pauses when hidden. Original owner ZIPs/downloads remain private. Anyone holding an enabled album link can view/copy its previews; revocation blocks future requests, not screenshots or copies already made.
5. **Event appearance:** logo, welcome text and button color apply to guest upload and public album/slideshow pages. Logos accept JPEG/PNG/WebP up to 2 MiB and 2000 px/side, and are re-encoded into private PNGs fitting 512 px. Text is escaped and button foreground adjusts for contrast. No external font or image service is used.

Run `php artisan schedule:run` each minute in production: hourly `uploads:prune`, `photos:prune` and `exports:prune` clean private staging, due photos/trash and expired ZIPs. A local `schedule:work` process is only a development convenience. Pending resumable staging is limited to 20 files per collection (up to 200 MiB), separate from accepted-photo quota.
