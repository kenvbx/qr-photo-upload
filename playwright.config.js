import { defineConfig } from '@playwright/test';
import { resolve } from 'node:path';
export default defineConfig({
  testDir: './e2e', workers: 1, fullyParallel: false,
  use: { baseURL: 'http://127.0.0.1:8037', viewport: { width: 1440, height: 1000 }, trace: 'retain-on-failure' },
  globalSetup: './e2e/setup.js',
  webServer: {
    command: 'php -d upload_max_filesize=10M -d post_max_size=110M -S 127.0.0.1:8037 -t backend/public',
    url: 'http://127.0.0.1:8037/login', reuseExistingServer: false, stdout: 'ignore', stderr: 'ignore',
    env: { APP_ENV: 'local', APP_URL: 'http://127.0.0.1:8037', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve('backend/database/e2e.sqlite'), SESSION_DRIVER: 'database', CACHE_STORE: 'database', PRIVATE_STORAGE_ROOT: resolve('backend/storage/app/e2e-private') }
  }
});
