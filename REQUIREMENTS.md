# MVP acceptance criteria

- Admin signs in with configured credentials; wrong credentials are rejected and login attempts rate-limited. Session ID regenerates on login; logout invalidates it. No public registration.
- Admin creates a named collection with expiry between 1 hour and 7 days, sees a QR code and copyable URL, and can close collection immediately.
- Before uploading, a guest sees local thumbnails, filenames and sizes, can remove individual photos with touch or keyboard, and sees selected count/total size. Removed files are excluded from the actual upload. Invalid/oversized images show actionable errors. Choosing again replaces the selection; removing all restores the required-file state. Previews never trigger an upload.
- A guest opens a valid active URL and sees the title, limits and a multiple-file input. Guest never sees existing photos or admin data.
- Backend accepts JPEG/PNG/WebP that decode as images, validates actual content, dimensions, individual size (10 MB), batch count (10) and collection count (100). SVG, malformed files, inactive sessions and over-quota requests fail clearly.
- Random 256-bit upload tokens are unguessable and redacted from application audit records. Gallery/download/delete routes require an authenticated collection owner. UUID storage names ignore user-supplied paths.
- Image bytes remain on a private disk; display and download pass through owner checks. Gallery has empty state, mobile layout, photo count, refresh and delete confirmation.
- Owner opens gallery photos in an accessible lightbox with loading/error states, previous/next, arrow keys, Escape, contained focus and focus restoration; browsing is scoped to the current page. Photo originals remain the fallback without JavaScript.
- Owner selects individual photos or all 24 on the current page; the viewer and grid share selection. Selected originals download as a private ZIP, or selected photos are deleted with browser confirmation and per-photo audit events. Server validates the complete selection and collection ownership before any action; failures preserve affected metadata and provide retry feedback. Native checkboxes and bulk forms work without JavaScript, with a visible permanent-deletion warning.
- Concurrent uploads lock the collection row on MySQL before checking quota; stored files are removed on transaction failure. Closing or expiry is checked inside the same transaction.
- Each selected photo shows actual transfer progress and a separate confirmation state. A failed photo can be retried without resending successful photos. Network/5xx failures receive at most one automatic retry per attempt; manual retry reuses the same UUID and obeys server cooldown. Permanent validation/link/session failures provide actionable feedback.
- Server persists a receipt atomically with photos and returns the previous result for matching replay; same key with different bytes returns 409. Identical file bytes in the same collection are stored once, including within a native batch and across new request IDs. Only new unique photos count toward quota. Receipt replay never resurrects an admin-deleted photo. Completed receipts can acknowledge acceptance after expiry; new work is still rejected.
- New unique photos enqueue a durable database thumbnail job in the upload transaction. Queue insertion failure rolls back upload metadata/receipt and cleans up original storage. Replay and content duplicates do not enqueue new jobs.
- A worker creates private JPEG previews fitting 480 px without upscaling, supports JPEG/PNG/WebP and preserves originals. EXIF-aware rotation is available when PHP EXIF is enabled. Gallery uses a protected thumbnail URL with a bounded placeholder while pending/unavailable; originals remain available for lightbox, download and ZIP.
- Thumbnail jobs have bounded retries, timeout and failed-job recording; a backfill command repairs pending/missing previews and optionally retries failures. Rendering is idempotent and serialized against deletion; deleting a photo removes final/temporary derivatives, and late jobs never recreate deleted photos.
- Tests cover auth, ownership, valid upload, invalid type/size, quota, expiry, closure, private download and deletion. Manual smoke test covers QR and responsive forms.

## Later
Malware scanning, retention/cleanup scheduling, monitoring alerts, resumable uploads, object storage. Never describe these as implemented until verified.

## Queued ZIP acceptance

- Selecting Download ZIP queues work atomically with an owner-private export record and redirects to a status page without building an archive in the web request. Identical sorted selections reuse unexpired active/ready requests under concurrency.
- Status shows queued, processing, ready, failed or expired, polls without auto-download and offers a manual refresh fallback. Gallery links recent requests. Ready downloads preserve original bytes and safe entry names, require the owner, and expire in 24 hours.
- Builds retry at most 3 attempts with 10/60-second exception backoff and a 60-second timeout. Incomplete archives are not downloadable; failed requests can be retried, and duplicate retry does not enqueue extra work while active/ready.
- Missing/deleted sources never produce a partial success. Deleting a selected source revokes access to an already-built ZIP. Source and export locks serialize normal build/delete/prune/retry mutations.
- Hourly scheduler definition plus exports:prune remove expired final/building files and metadata; storage cleanup failure preserves the record. Expiry blocks access even without scheduler execution, and late jobs cannot recreate pruned exports.
