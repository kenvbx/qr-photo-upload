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

Targets are assumptions, not benchmark claims. Track upload successes/failures, disk use, request latency and server errors. A health endpoint is Laravel /up. No queue is needed in the synchronous MVP; thumbnails should become queued work when introduced.
