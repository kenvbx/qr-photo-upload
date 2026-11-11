import { execFileSync } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';
export default async function () {
  const database = resolve('backend/database/e2e.sqlite');
  rmSync(resolve('backend/storage/app/e2e-private'), { recursive: true, force: true });
  writeFileSync(database, '');
  const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: database };
  execFileSync('php', ['artisan', 'migrate', '--force'], { cwd: resolve('backend'), env });
  execFileSync('php', ['tests/browser-setup.php'], { cwd: resolve('backend'), env });
}
