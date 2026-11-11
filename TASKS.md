# Delivery

- [x] Recover project memory and define MVP, security and architecture.
- [x] Bootstrap Laravel and QR rendering.
- [x] Implement admin login, ownership and collection lifecycle.
- [x] Implement private validated upload and gallery/download/delete.
- [x] Verify meaningful feature tests and browser smoke checks.
- [x] Document local/demo setup, deployment and known limits.
- [x] Verify MySQL concurrency with real parallel uploads in CI.
- [ ] Deploy a demo when requested/configured.

Verified locally 2026-11-12: 56 backend tests on SQLite (512 assertions; 4 MySQL-only tests skipped), and 14 Chromium end-to-end tests. GitHub Actions runs the full backend suite on MySQL, including real parallel uploads. Pending: physical phone, public demo deployment and operational restore drill.

- [x] Guest photo previews, filename/size, keyboard-accessible removal, client validation feedback and browser regression coverage.

- [x] Per-photo transfer/confirmation progress, bounded automatic retry and explicit retry of failed photos.
- [x] Transactional upload receipts, key-conflict handling, SHA-256 content deduplication and native form compatibility.
- [x] Add an isolated MySQL CI job and confirm real parallel-process replay/quota tests pass.

- [x] Accessible gallery lightbox, keyboard navigation/focus, image loading/error recovery and mobile layout.
- [x] Current-page photo selection, private original ZIP downloads, confirmed bulk deletion and authorization/failure regression coverage.

- [x] Transactional database thumbnail queue, private bounded GD derivatives, retries/failed jobs and old-photo backfill/repair.
- [x] Real worker browser coverage, safe deletion/late jobs, EXIF/transparency, private placeholders and SQLite background-worker locking.
- [x] Add a two-process MySQL thumbnail-generation/deletion race to GitHub CI; confirm its run before claiming verification.

- [x] Queued private ZIPs, atomic request/job creation, duplicate-selection reuse, status polling/manual fallback and owner-only retry/download.
- [x] 24-hour expiry/prune schedule, partial-build cleanup, source deletion revocation and real-worker browser coverage; MySQL simultaneous ZIP requests included in CI.
