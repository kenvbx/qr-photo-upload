# Architecture

## Decision
Laravel modular monolith with responsive Blade pages and MySQL metadata; private local image disk. This keeps cookie authentication, CSRF and server validation in one origin with no additional frontend deployment. SQLite is available for local setup/tests; MySQL is the deployment target and must be verified separately for concurrency.

## Data and flow
User owns many UploadSessions; session has title, random unique upload token, expires_at, closed_at. Session has many Photos with randomized private path, detected MIME, byte count and pixel dimensions. AuditEvent records actor, action and resource IDs, never token or photo bytes.

Admin login → create collection → server-rendered SVG QR of APP_URL/u/{token} → guest upload → validate/decode → transaction + session row lock + quota → private storage + photo rows → private owner gallery.

## Security and reliability
Session cookies, password hashing, owner checks, CSRF, bounded requests, MIME and pixel validation, random storage paths, upload and login throttles. Only allow JPEG/PNG/WebP. Guest token is a bearer credential: sharing permits uploads until close/expiry. Do not log token-bearing URLs at proxy level. Originals may contain EXIF/GPS; MVP keeps originals privately and warns guests. No promise of malware scanning or metadata stripping.

DB and filesystem are not an atomic resource: compensate failed uploads by deleting created files. Deletion removes bytes before its row; failed filesystem deletion preserves metadata for retry. A process crash may leave orphan files and requires reconciliation. Sessions expire for contribution; photos remain available to owner until deletion. Do not expose storage with a public symlink.

## Operations and tradeoffs
Single-instance local storage is simple but has a single point of failure. Back up database and private disk together and test restore. HTTPS is required for deployment; use narrow trusted proxies, secure cookies and debug off. APP_URL must be reachable from phone; localhost QR works only on the host itself. No unrequested public deployment.

Targets are assumptions, not benchmark claims. Track upload successes/failures, disk use, request latency and server errors. A health endpoint is Laravel /up. Original upload validation remains synchronous. Database-queued thumbnails reduce gallery image transfer without delaying the acceptance response for resizing.

## Reliable uploads

Browser sends files sequentially via XMLHttpRequest, keeping a random per-file UUID stable across automatic/manual retries. Upload events report actual transfer progress; server acknowledgement decides completion. Retry only transient failures once automatically with 750 ms backoff; manual retries target failed eligible files only. Rate-limit cooldown and terminal link/auth errors are visible. JavaScript-disabled clients keep a batch multipart form and server-rendered upload key.

Photo metadata gains nullable `sha256` with a unique `(upload_session_id, sha256)` constraint. A separate `upload_receipts` table has unique `(upload_session_id, upload_key)`, a fingerprint of ordered file hashes, created/duplicate counts and creation time. Validate/decode/hash incoming files before taking a transaction lock. Under the collection row lock: check an existing receipt and fingerprint first; only fresh work requires an active session; hash legacy rows with readable private bytes; skip existing and in-batch hashes; check quota for new unique files; save private bytes/metadata, receipt and audit in one DB transaction. Normal write failures roll back the receipt and compensate file writes. Do not enable DB-level automatic transaction retries around filesystem writes.

A replay returns historical acceptance even if the organizer later closed/expired the collection or deleted the photo. It does not recreate files. A key conflict returns 409; a new request on a closed collection returns 410. New keys with identical content skip extra copies while the original photo still exists. Deduplication compares exact bytes and is scoped to the collection. Receipt checks and unique indexes protect the transaction in addition to InnoDB row locking; a separate MySQL CI job exercises real parallel workers. SQLite tests do not establish InnoDB behavior.

Legacy hash backfill is lazy and preserves old duplicate files; their first subsequent upload may incur up to the existing collection's worth of disk reads. Incomplete/missing legacy files retain metadata for reconciliation. Receipts live for the collection lifetime; public deployment needs a retention policy. A hard process crash can still orphan private files because SQL and filesystem commits are not one atomic resource. Browser progress/retry state is not persisted; reselecting bytes after a page reload is safe through content hashes, but this is not chunk-resumable upload.

## Gallery lightbox and bulk selection

Blade renders 24 photos per page with deterministic timestamp/ID ordering. Native dialog provides modal semantics; vanilla JavaScript contains Tab focus, restores focus, handles arrow/Escape keys and syncs viewer selection with external form checkboxes. Image loading uses a fresh element per navigation so stale responses cannot overwrite the current photo. Without dialog support, original private links still work.

The authenticated selection route validates owner, action and 1–24 distinct IDs scoped to the collection. Download actions now queue an export and redirect to its private status page. ZIP builds use built-in PharData under a dedicated database queue, retaining private archives for repeat downloads until 24-hour expiry. No ZIP/original bytes are loaded into a single application string; selected bytes and retained/building capacity must be budgeted on private storage.

Individual and bulk deletion share PhotoRemoval: row lock, delete bytes, audit, remove row. A normal filesystem failure retains metadata. Each photo commits separately and batch feedback reports partial success; DB/file crash reconciliation remains necessary. Bulk actions keep guest access and private image authorization unchanged.

## Queued thumbnails

Photos track pending/ready/failed thumbnail state and derived dimensions/byte size. Each newly stored photo dispatches an integer-ID job to the database `thumbnails` queue before commit; jobs and photos intentionally share the application's default DB connection. The job row, photo, receipt and audit commit atomically. If enqueueing fails, normal upload compensation removes the original. No after-commit broker call can turn a committed receipt into an upload error. Duplicate receipt/content paths enqueue nothing.

The worker acquires a per-photo mutation lock, returns for deleted/already-ready photos, validates bounded input again, uses GD to produce a JPEG fitting 480 px without upscaling, and publishes via temporary-file rename before marking ready. With EXIF enabled, all 8 JPEG orientations are supported; PNG/WebP alpha is flattened onto white. Stored originals remain byte-identical. Deletion takes the same lock and removes final/temporary derivatives before original bytes and metadata; if removing an original fails after removing its derivative, backfill can repair the missing preview. Lock duration includes decoding to avoid generation/deletion races in this local-disk MVP.

MySQL uses InnoDB row locks. SQLite is a local/test option: WAL and a 10-second busy timeout permit concurrent readers, while app photo mutations/upload transactions reserve a writer before reads on PHP 8.3 to avoid deferred-read lock-upgrade failures. Immediate transactions cover supported newer PHP versions. SQLite still serializes writes; do not treat browser tests as evidence of MySQL locking behavior. CI includes two-process thumbnail-generation/deletion coverage on MySQL.

Jobs allow 3 attempts with 10/60-second exception backoff, timeout 60 seconds and database retry_after 90 seconds. Exhaustion is recorded in failed_jobs and marks the thumbnail failed unless another attempt already produced a ready preview. photos:thumbnails backfills pending/missing derivatives, optionally scopes to one collection and requeues exhausted failures. It can enqueue redundant pending jobs; the renderer is idempotent. A separately supervised worker and shared private storage are required. Normal partial-write failures are compensated under the lock; hard crashes still need reconciliation. The thumbnail route serves owner-only JPEGs or bounded static placeholders with no-store, while gallery refresh updates readiness.

## Queued ZIP exports

PhotoExport has a UUID ID, collection FK, immutable selected-ID JSON/fingerprint, queued/processing/ready/failed state, size and expiry. Request creation uses the collection lock to reuse unexpired identical selections and inserts metadata plus a database/exports job in one transaction. No archive work occurs in the HTTP selection request.

A worker commits processing state, then builds under the export lock and ordered source-photo locks. Deletion uses those same source locks. Inputs are bounded to 24 photos/10 MB each. A deterministic building ZIP is atomically renamed to the final path before ready is committed. Normal failures remove partial files under lock; retries are bounded and exhaustion records failed_jobs. A ready archive is idempotent. Source changes/removal cannot produce a silently partial archive.

Owner-only status supports HTML and minimal JSON with no-store. The browser polls every 2 seconds while active and 15 seconds after completion, recovers from transient failures and never auto-downloads. Download rechecks expiry, readiness, storage and selected-photo membership; deleting a source revokes access even if its ZIP snapshot remains privately stored until cleanup. Already transmitted bytes cannot be revoked. Retry takes the export lock and renews expiry only for a failed/expired/missing export whose source rows still exist.

Hourly exports:prune locks each expired export, deletes final/building files and then metadata; failed deletion retains the record for retry. Scheduler installation and supervising workers are deployment responsibilities. Expiry is enforced independently of scheduler availability. Prune/retry/build share lock order, late jobs for pruned exports are no-ops, and recent requests remain discoverable from the collection. Original retention is unchanged.


### Collection controls and storage adapters

Photo metadata now records disk, escaped display filename and optional fixed delete_after deadline. The collection lock serializes upload byte/count quota checks, settings changes and password validation with receipt replay. Password hashes stay hidden and session grants are bound to the current hash. New photo retention is opt-in; scheduled cleanup reuses photo mutation locks/removal and revokes ZIP downloads through source membership checks. Quota sums original bytes; thumbnail/export storage is additional.

Originals and thumbnails use each photo's disk (local/s3/r2), preserving local history when changing the configured new-upload disk. GD reads bounded bytes and EXIF from an in-memory stream. ZIP source locks protect remote download staging; deterministic private export staging is cleaned on retry, failure and expiry. Archives always use the local private disk. Source object requests are server-side only. Cloud application flows are covered with fake remote disks, without real provider verification.

Owner gallery/task polling returns rendered partials and hashes over same-origin authenticated JSON every five seconds. Gallery replacement retains selected IDs, restores focused photo controls and waits for lightbox completion, and pauses while hidden; errors expose reconnection/manual refresh. Search values are bound and literal LIKE wildcards escaped; sort/type use allowlists. Compression caches one optional encoded File per upload ID, so retry receipts see identical content.


### Resumable transfer and photo lifecycle

The optional resumable protocol persists prepared Files and random upload secrets in browser IndexedDB, then accepts sequential bounded 512 KiB chunks on private local storage. Server rows contain UUID, collection FK, hashed secret, immutable metadata/checksum, committed offset, optional final result and 24-hour expiry. Every mutation locks collection then upload row; password grants and active-link rules are checked again. Repeated identical chunks are accepted; mismatched overlap/out-of-order chunks fail. Chunk data is flushed/fsynced before committing its offset. Reconciliation truncates uncommitted tails, or resets lost staging safely. Finalization validates complete size, SHA-256 and actual image content before using the existing receipt/deduplication/quota transaction. Final results replay without new photos/jobs; simultaneous finalization has a real two-process MySQL regression test. Failed requests never expose staging paths. uploads:prune cleans expired rows/files under the same locks. All app instances require shared persistent local staging, branding and export storage, including when originals use S3/R2. Browser persistence is opt-in, expires logically after 24h and is removed on a later visit; it is not a background uploader.

Soft-deleted photos retain originals/derivatives and unique hashes. Quota/deduplication include trashed rows, while galleries, thumbnail generation and ZIP source authorization exclude them. Ordinary deletion records photo.trashed; restoring resets review state and repairs missing previews. Permanent purge and photos:prune use the per-photo writer lock and recheck that the row is still trashed/due to avoid deleting a concurrently restored photo. The earliest retention/trash deadline applies. Original-byte quota is only freed by physical removal.

### Publication and branding

Upload bearer tokens cannot read the public album; sharing uses a distinct random token, opt-in enabled flag and independent expiry. Public album metadata/media/logo endpoints all recheck sharing on every request. Only non-trashed approved/ready JPEG thumbnails are served; originals remain owner-only. Moderation is collection-scoped and photo-locked with audits. Album polling removes rejected/trashed photos and closes a removed current slide. Disable/rotation/expiry denies all old media endpoints. Revocation cannot erase prior viewer copies.

Event logos are bounded, re-encoded PNGs on the local private disk, with owner/authorized guest/enabled-album routes; welcome text is escaped and brand colors accept only six-digit hex. Foreground selection uses relative luminance for accessible button contrast. PageSession extends Laravel StartSession solely to exclude image/media/polling URLs from previous-page tracking; otherwise Referrer-Policy=no-referrer could redirect a form to a thumbnail. Successful mutations use explicit named redirects.
