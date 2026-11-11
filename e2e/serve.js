import setup from './setup.js';
import { spawn } from 'node:child_process';
import { resolve } from 'node:path';

// Initialize isolated storage/database before either process starts.
await setup();
const server = spawn('php', [
  '-d', 'upload_max_filesize=10M',
  '-d', 'post_max_size=110M',
  '-d', 'memory_limit=256M',
  '-S', '127.0.0.1:8037', '-t', 'backend/public'
], { stdio: 'inherit', env: process.env });
const worker = spawn('php', [
  '-d', 'memory_limit=256M', 'artisan', 'queue:work', 'database',
  '--queue=thumbnails', '--sleep=1', '--tries=3', '--timeout=60', '--memory=256'
], { cwd: resolve('backend'), stdio: 'inherit', env: process.env });
let stopping = false;
function stop(code) {
  if (stopping) return;
  stopping = true;
  process.exitCode = code;
  server.kill('SIGTERM');
  worker.kill('SIGTERM');
}
for (const child of [server, worker]) {
  child.on('error', error => { console.error(error); stop(1); });
  child.on('exit', code => { if (!stopping) stop(code || 1); });
}
process.on('SIGTERM', () => stop(0));
process.on('SIGINT', () => stop(0));
