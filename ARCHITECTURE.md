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

The authenticated selection route validates owner, action and 1–24 distinct IDs scoped to the collection before doing work. ZIP creation uses built-in PharData and private OS temporary files (restrictive umask), then BinaryFileResponse removes the archive after sending. Build failures clean up and return gallery feedback; abrupt process exits require operational cleanup. No ZIP content or original bytes are loaded into a single application string. Batch size and per-owner rate limits bound work, while temp disk capacity must accommodate the selected originals.

Individual and bulk deletion share PhotoRemoval: row lock, delete bytes, audit, remove row. A normal filesystem failure retains metadata. Each photo commits separately and batch feedback reports partial success; DB/file crash reconciliation remains necessary. Bulk actions keep guest access and private image authorization unchanged.

## Queued thumbnails

Photos track pending/ready/failed thumbnail state and derived dimensions/byte size. Each newly stored photo dispatches an integer-ID job to the database `thumbnails` queue before commit; jobs and photos intentionally share the application's default DB connection. The job row, photo, receipt and audit commit atomically. If enqueueing fails, normal upload compensation removes the original. No after-commit broker call can turn a committed receipt into an upload error. Duplicate receipt/content paths enqueue nothing.

The worker acquires a per-photo mutation lock, returns for deleted/already-ready photos, validates bounded input again, uses GD to produce a JPEG fitting 480 px without upscaling, and publishes via temporary-file rename before marking ready. With EXIF enabled, all 8 JPEG orientations are supported; PNG/WebP alpha is flattened onto white. Stored originals remain byte-identical. Deletion takes the same lock and removes final/temporary derivatives before original bytes and metadata; if removing an original fails after removing its derivative, backfill can repair the missing preview. Lock duration includes decoding to avoid generation/deletion races in this local-disk MVP.

MySQL uses InnoDB row locks. SQLite is a local/test option: WAL and a 10-second busy timeout permit concurrent readers, while app photo mutations/upload transactions reserve a writer before reads on PHP 8.3 to avoid deferred-read lock-upgrade failures. Immediate transactions cover supported newer PHP versions. SQLite still serializes writes; do not treat browser tests as evidence of MySQL locking behavior. CI includes two-process thumbnail-generation/deletion coverage on MySQL.

Jobs allow 3 attempts with 10/60-second exception backoff, timeout 60 seconds and database retry_after 90 seconds. Exhaustion is recorded in failed_jobs and marks the thumbnail failed unless another attempt already produced a ready preview. photos:thumbnails backfills pending/missing derivatives, optionally scopes to one collection and requeues exhausted failures. It can enqueue redundant pending jobs; the renderer is idempotent. A separately supervised worker and shared private storage are required. Normal partial-write failures are compensated under the lock; hard crashes still need reconciliation. The thumbnail route serves owner-only JPEGs or bounded static placeholders with no-store, while gallery refresh updates readiness.
