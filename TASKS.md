# Delivery

- [x] Recover project memory and define MVP, security and architecture.
- [x] Bootstrap Laravel and QR rendering.
- [x] Implement admin login, ownership and collection lifecycle.
- [x] Implement private validated upload and gallery/download/delete.
- [x] Verify meaningful feature tests and browser smoke checks.
- [x] Document local/demo setup, deployment and known limits.
- [x] Verify MySQL concurrency with real parallel uploads in CI.
- [ ] Deploy a demo when requested/configured.

Verified 2026-11-11: 30 backend tests on MySQL (201 assertions), 28 on SQLite (188 assertions; 2 MySQL-only tests skipped), and 9 Chromium end-to-end tests. GitHub Actions run 43025004353 passed both jobs. Pending: physical phone, public demo deployment and operational restore drill.

- [x] Guest photo previews, filename/size, keyboard-accessible removal, client validation feedback and browser regression coverage.

- [x] Per-photo transfer/confirmation progress, bounded automatic retry and explicit retry of failed photos.
- [x] Transactional upload receipts, key-conflict handling, SHA-256 content deduplication and native form compatibility.
- [x] Add an isolated MySQL CI job and confirm real parallel-process replay/quota tests pass.
