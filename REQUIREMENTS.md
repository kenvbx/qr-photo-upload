# MVP acceptance criteria

- Admin signs in with configured credentials; wrong credentials are rejected and login attempts rate-limited. Session ID regenerates on login; logout invalidates it. No public registration.
- Admin creates a named collection with expiry between 1 hour and 7 days, sees a QR code and copyable URL, and can close collection immediately.
- Before uploading, a guest sees local thumbnails, filenames and sizes, can remove individual photos with touch or keyboard, and sees selected count/total size. Removed files are excluded from the actual upload. Invalid/oversized images show actionable errors. Choosing again replaces the selection; removing all restores the required-file state. Previews never trigger an upload.
- A guest opens a valid active URL and sees the title, limits and a multiple-file input. Guest never sees existing photos or admin data.
- Backend accepts JPEG/PNG/WebP that decode as images, validates actual content, dimensions, individual size (10 MB), batch count (10) and collection count (100). SVG, malformed files, inactive sessions and over-quota requests fail clearly.
- Random 256-bit upload tokens are unguessable and redacted from application audit records. Gallery/download/delete routes require an authenticated collection owner. UUID storage names ignore user-supplied paths.
- Image bytes remain on a private disk; display and download pass through owner checks. Gallery has empty state, mobile layout, photo count, refresh and delete confirmation.
- Concurrent uploads lock the collection row on MySQL before checking quota; stored files are removed on transaction failure. Closing or expiry is checked inside the same transaction.
- Each selected photo shows actual transfer progress and a separate confirmation state. A failed photo can be retried without resending successful photos. Network/5xx failures receive at most one automatic retry per attempt; manual retry reuses the same UUID and obeys server cooldown. Permanent validation/link/session failures provide actionable feedback.
- Server persists a receipt atomically with photos and returns the previous result for matching replay; same key with different bytes returns 409. Identical file bytes in the same collection are stored once, including within a native batch and across new request IDs. Only new unique photos count toward quota. Receipt replay never resurrects an admin-deleted photo. Completed receipts can acknowledge acceptance after expiry; new work is still rejected.
- Tests cover auth, ownership, valid upload, invalid type/size, quota, expiry, closure, private download and deletion. Manual smoke test covers QR and responsive forms.

## Later
Thumbnail queue, malware scanning, retention/cleanup scheduling, monitoring alerts, resumable uploads, object storage. Never describe these as implemented until verified.
