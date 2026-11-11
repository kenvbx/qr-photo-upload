import setup from './setup.js';
import { spawn } from 'node:child_process';

// Prepare isolated fixtures before Playwright probes the database-backed login page.
await setup();
const server = spawn('php', [
  '-d', 'upload_max_filesize=10M',
  '-d', 'post_max_size=110M',
  '-d', 'memory_limit=256M',
  '-S', '127.0.0.1:8037', '-t', 'backend/public'
], { stdio: 'inherit', env: process.env });
server.on('error', error => { console.error(error); process.exit(1); });
server.on('exit', code => process.exit(code ?? 0));
process.on('SIGTERM', () => server.kill('SIGTERM'));
process.on('SIGINT', () => server.kill('SIGINT'));
