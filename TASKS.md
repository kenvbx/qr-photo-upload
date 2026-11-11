# Delivery

- [x] Recover project memory and define MVP, security and architecture.
- [x] Bootstrap Laravel and QR rendering.
- [x] Implement admin login, ownership and collection lifecycle.
- [x] Implement private validated upload and gallery/download/delete.
- [x] Verify meaningful feature tests and browser smoke checks.
- [x] Document local/demo setup, deployment and known limits.
- [ ] Verify MySQL concurrency and deploy a demo when requested/configured.

Verified 2026-11-11: 17 backend tests (112 assertions), 4 Chromium end-to-end tests. Pending: MySQL concurrency, physical phone, public demo deployment and operational restore drill.

- [x] Guest photo previews, filename/size, keyboard-accessible removal, client validation feedback and browser regression coverage.

- [x] Per-photo transfer/confirmation progress, bounded automatic retry and explicit retry of failed photos.
- [x] Transactional upload receipts, key-conflict handling, SHA-256 content deduplication and native form compatibility.
- [x] Add an isolated MySQL CI job and real parallel-process replay/quota tests; confirm its run before marking MySQL verification complete.
