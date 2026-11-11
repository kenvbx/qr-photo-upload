import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import jsQR from 'jsqr';
let organizerCookies = [];
async function login(page) {
 if (organizerCookies.length) {
  await page.context().addCookies(organizerCookies);
  await page.goto('/');
  if (new URL(page.url()).pathname === '/') {
   await expect(page.getByRole('heading', { name: 'Your collections.' })).toBeVisible();
   return;
  }
 }
 await page.goto('/login');
 await page.getByLabel('Email address').fill('organizer@example.test');
 await page.getByLabel('Password', { exact: true }).fill('local-demo-photos-2026');
 await page.getByRole('button', { name: 'Sign in to workspace' }).click();
 await expect(page.getByRole('heading', { name: 'Your collections.' })).toBeVisible();
 organizerCookies = await page.context().cookies();
}
test('QR decodes to real guest link; upload, private gallery, download, delete and expiry', async ({ page, browser }) => {
 await login(page);
 const csrfRejected = await page.request.post('/collections', { form: { title: 'No CSRF', hours: 24 } });
 expect(csrfRejected.status()).toBe(419);
 await page.getByLabel('Collection name').fill('A weekend together');
 await page.getByRole('button', { name: 'Create collection' }).click();
 await expect(page.getByRole('heading', { name: 'A weekend together' })).toBeVisible();
 const url = await page.getByLabel('Guest upload link').inputValue();
 const qr = PNG.sync.read(await page.getByRole('img', { name: /QR code linking/ }).screenshot());
 const decoded = jsQR(new Uint8ClampedArray(qr.data), qr.width, qr.height);
 expect(decoded?.data).toBe(url);
 const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
 const guest = await context.newPage();
 await guest.goto(decoded.data);
 await expect(guest.getByRole('heading', { name: 'Add your photos' })).toBeVisible();
 expect(await guest.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 const image = new PNG({ width: 40, height: 30 });
 for (let i=0;i<image.data.length;i+=4) { image.data[i]=74;image.data[i+1]=124;image.data[i+2]=94;image.data[i+3]=255; }
 await guest.locator('input[type=file]').setInputFiles({ name: 'weekend.png', mimeType: 'image/png', buffer: PNG.sync.write(image) });
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByRole('status').filter({hasText:'Thank you!'})).toBeVisible();
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 const photo = page.locator('.photo-card').first();
 await expect(photo).toBeVisible();
 const imageUrl = await photo.locator('img').getAttribute('src');
 const privateResponse = await context.request.get(imageUrl, { maxRedirects: 0 });
 expect(privateResponse.status()).toBe(302);
 const downloadPromise = page.waitForEvent('download');
 await photo.getByRole('link', { name: 'Download' }).click();
 expect((await downloadPromise).suggestedFilename()).toMatch(/^photo-\d+\.png$/);
 await page.screenshot({ path: 'screenshots/collection-desktop.png', fullPage: true });
 await guest.screenshot({ path: 'screenshots/upload-mobile.png', fullPage: true });
 page.on('dialog', dialog => dialog.accept());
 await photo.getByRole('button', { name: 'Delete', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Ready for the first photo' })).toBeVisible();
 await page.getByRole('button', { name: 'Close upload link' }).click();
 const closed = await guest.goto(url);
 expect(closed.status()).toBe(410);
 await expect(guest.getByRole('heading', { name: 'Uploads are closed.' })).toBeVisible();
 await context.close();
});
test('responsive organizer screens and logout', async ({ page }) => {
 await page.setViewportSize({ width: 375, height: 812 });
 await login(page);
 expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await page.screenshot({ path: 'screenshots/dashboard-mobile.png', fullPage: true });
 await page.getByRole('button', { name: 'Sign out' }).click();
 await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
});

function previewImage(name, color = [74, 124, 94], width = 80, height = 60) {
 const image = new PNG({ width, height });
 for (let i = 0; i < image.data.length; i += 4) {
  image.data[i] = color[0]; image.data[i + 1] = color[1]; image.data[i + 2] = color[2]; image.data[i + 3] = 255;
 }
 return { name, mimeType: 'image/png', buffer: PNG.sync.write(image) };
}

async function createPreviewCollection(page, title) {
 await login(page);
 await page.getByLabel('Collection name').fill(title);
 await page.getByRole('button', { name: 'Create collection' }).click();
 return page.getByLabel('Guest upload link').inputValue();
}

test('guest previews photos locally, removes a file with keyboard and uploads only the remaining photos', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Preview selection');
 const context = await browser.newContext({ viewport: { width: 375, height: 812 } });
 const guest = await context.newPage();
 const uploadRequests = [];
 guest.on('request', request => { if (request.method() === 'POST') uploadRequests.push(request); });
 await guest.goto(url);
 const photos = guest.locator('input[type=file]');
 const first = previewImage('family.png');
 const removed = previewImage('remove-this.png', [190, 80, 60]);
 const last = previewImage('a-long-photo-name-<script>-&-memories.png', [64, 110, 170]);
 await photos.setInputFiles([first, removed, last]);
 await expect(guest.locator('.upload-preview')).toHaveCount(3);
 for (const image of await guest.locator('.upload-preview img').all()) {
  await expect(image).toBeVisible();
  await expect.poll(() => image.evaluate(element => element.complete && element.naturalWidth > 0)).toBe(true);
 }
 await expect(guest.getByText(last.name, { exact: true })).toBeVisible();
 await expect(guest.locator('.preview-size').first()).toHaveText(/KB/);
 await expect(guest.locator('#file-info')).toContainText('3 photos selected');
 expect(uploadRequests).toHaveLength(0);
 expect(await guest.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 const remove = guest.getByRole('button', { name: 'Remove photo remove-this.png', exact: true });
 await remove.focus();
 await guest.keyboard.press('Enter');
 await expect(guest.locator('.upload-preview')).toHaveCount(2);
 await expect(guest.getByRole('button', { name: `Remove photo ${last.name}`, exact: true })).toBeFocused();
 await expect(guest.locator('#file-info')).toContainText('2 photos selected');
 expect(await photos.evaluate(input => [...input.files].map(file => file.name))).toEqual([first.name, last.name]);
 await guest.screenshot({ path: 'screenshots/upload-preview-mobile.png', fullPage: true });
 await guest.setViewportSize({ width: 1440, height: 1000 });
 await guest.screenshot({ path: 'screenshots/upload-preview-desktop.png', fullPage: true });
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByRole('status').filter({ hasText: 'Thank you! 2 photo(s) confirmed' })).toBeVisible();
 expect(uploadRequests).toHaveLength(2);
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(2);
 const saved = [];
 for (const link of await page.locator('[data-lightbox-photo]').all()) {
  const response = await page.request.get(await link.getAttribute('href'));
  expect(response.ok()).toBe(true);
  saved.push((await response.body()).toString('base64'));
 }
 expect(saved.sort()).toEqual([first.buffer.toString('base64'), last.buffer.toString('base64')].sort());
 await context.close();
});

test('preview selection can recover from too many, oversized, unsupported and corrupt files, replacement and removing all', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Preview validation');
 const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
 const guest = await context.newPage();
 await guest.goto(url);
 const photos = guest.locator('input[type=file]');
 await photos.setInputFiles(Array.from({ length: 11 }, (_, index) => previewImage(`photo-${index}.png`)));
 await expect(guest.locator('#file-info')).toContainText('Choose no more than 10');
 expect(await photos.evaluate(input => input.validity.valid)).toBe(false);
 await guest.getByRole('button', { name: 'Remove photo photo-10.png', exact: true }).click();
 await expect(guest.locator('#file-info')).toContainText('10 photos selected');
 expect(await photos.evaluate(input => input.validity.valid)).toBe(true);
 await photos.setInputFiles([
  previewImage('keep.png'),
  { name: 'too-large.png', mimeType: 'image/png', buffer: Buffer.alloc(10 * 1024 * 1024 + 1) },
  { name: 'unsupported.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') },
  { name: 'broken.png', mimeType: 'image/png', buffer: Buffer.from('not a valid image') }
 ]);
 await expect(guest.locator('.upload-preview')).toHaveCount(4);
 await expect(guest.locator('.upload-previews').getByText('This photo exceeds the 10 MB limit.')).toBeVisible();
 await expect(guest.getByText('Choose a JPEG, PNG or WebP image.', { exact: true })).toBeVisible();
 await expect(guest.getByText('This image could not be previewed. Remove it or choose a different file.')).toBeVisible();
 expect(await photos.evaluate(input => input.validity.valid)).toBe(false);
 for (const name of ['too-large.png', 'unsupported.svg', 'broken.png']) {
  await guest.getByRole('button', { name: `Remove photo ${name}`, exact: true }).click();
 }
 await expect(guest.locator('#file-info')).toContainText('1 photo selected');
 expect(await photos.evaluate(input => input.validity.valid)).toBe(true);
 await guest.getByRole('button', { name: 'Remove photo keep.png', exact: true }).click();
 await expect(guest.locator('.upload-selection')).toBeHidden();
 await expect(photos).toBeFocused();
 expect(await photos.evaluate(input => input.files.length)).toBe(0);
 expect(await photos.evaluate(input => input.validity.valueMissing)).toBe(true);
 await photos.setInputFiles([previewImage('replacement.png')]);
 await expect(guest.locator('.upload-preview')).toHaveCount(1);
 await expect(guest.getByText('replacement.png', { exact: true })).toBeVisible();
 expect(await photos.evaluate(input => input.validity.valid)).toBe(true);
 await context.close();
});

test('real per-photo progress waits for confirmation; retry after lost responses preserves successful photos', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Safe retries');
 const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
 const guest = await context.newPage();
 const errors = [];
 guest.on('pageerror', error => errors.push(error.message));
 await guest.goto(url);
 let releaseFirst;
 let firstAccepted;
 const accepted = new Promise(resolve => { firstAccepted = resolve; });
 const release = new Promise(resolve => { releaseFirst = resolve; });
 const results = [];
 let requests = 0;
 const network = await context.newCDPSession(guest);
 await network.send('Fetch.enable', { patterns: [{ urlPattern: url, requestStage: 'Response' }] });
 network.on('Fetch.requestPaused', async event => {
  if (event.request.method !== 'POST') return network.send('Fetch.continueRequest', { requestId: event.requestId });
  const attempt = ++requests;
  expect(event.responseStatusCode).toBe(200);
  const response = await network.send('Fetch.getResponseBody', { requestId: event.requestId });
  const bytes = Buffer.from(response.body, response.base64Encoded ? 'base64' : 'utf8');
  results.push(JSON.parse(bytes.toString()).data);
  if (attempt === 1) { firstAccepted(); await release; }
  if (attempt <= 2) return network.send('Fetch.failRequest', { requestId: event.requestId, errorReason: 'ConnectionReset' });
  return network.send('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: event.responseStatusCode, responseHeaders: event.responseHeaders, body: bytes.toString('base64') });
 });
 await guest.locator('input[type=file]').setInputFiles([previewImage('retry-me.png'), previewImage('keep-success.png', [150, 80, 55])]);
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await accepted;
 const retryCard = guest.locator('.upload-preview').filter({ has: guest.getByText('retry-me.png', { exact: true }) });
 await expect(retryCard.getByText('Sent · confirming with server…')).toBeVisible();
 await expect(guest.getByRole('button', { name: 'Uploading…' })).toBeDisabled();
 await expect(retryCard.getByRole('progressbar')).toHaveJSProperty('value', 100);
 await expect(guest.locator('.upload-total-label')).toContainText('0 of 2 photos confirmed');
 releaseFirst();
 await expect(guest.getByRole('status').filter({ hasText: '1 failed' })).toBeVisible();
 expect(requests).toBe(3);
 expect(results[0]).toEqual({ uploaded: 1, duplicates: 0, replayed: false });
 expect(results[1]).toEqual({ uploaded: 1, duplicates: 0, replayed: true });
 await expect(retryCard.getByRole('button', { name: 'Retry photo retry-me.png' })).toBeEnabled();
 await guest.screenshot({ path: 'screenshots/upload-retry-mobile.png', fullPage: true });
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(2);
 await guest.getByRole('button', { name: 'Retry failed photos' }).click();
 await expect(guest.getByRole('status').filter({ hasText: 'Thank you! 2 photo(s) confirmed' })).toBeVisible();
 await expect(retryCard.getByText('Upload confirmed · safely retried')).toBeVisible();
 expect(requests).toBe(4);
 expect(results[3]).toEqual({ uploaded: 1, duplicates: 0, replayed: true });
 await expect(guest.getByRole('progressbar', { name: 'Overall upload progress' })).toHaveJSProperty('value', 100);
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(2);
 expect(errors).toEqual([]);
 await context.close();
});

test('network failure before receipt can be retried and selecting the same bytes again does not duplicate photos', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Network recovery');
 const context = await browser.newContext();
 const guest = await context.newPage();
 await guest.goto(url);
 let blocked = true;
 let requests = 0;
 await guest.route(url, async route => {
  if (route.request().method() !== 'POST') return route.continue();
  requests++;
  if (blocked) return route.abort('internetdisconnected');
  return route.continue();
 });
 const photo = previewImage('offline.png');
 await guest.locator('input[type=file]').setInputFiles(photo);
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByRole('status').filter({ hasText: '1 failed' })).toBeVisible();
 expect(requests).toBe(2);
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(0);
 blocked = false;
 await guest.getByRole('button', { name: 'Retry photo offline.png', exact: true }).click();
 await expect(guest.getByRole('status').filter({ hasText: 'Thank you! 1 photo(s) confirmed' })).toBeVisible();
 expect(requests).toBe(3);
 await guest.locator('input[type=file]').setInputFiles({ ...photo, name: 'same-photo-renamed.png' });
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByText('Already uploaded · no duplicate saved', { exact: true })).toBeVisible();
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(1);
 await context.close();
});

test('rate limit cooldown is respected and a closed collection stops pending photos', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Upload failure states');
 const context = await browser.newContext();
 const guest = await context.newPage();
 await guest.clock.install();
 await guest.goto(url);
 let requests = 0;
 await guest.route(url, route => {
  if (route.request().method() !== 'POST') return route.continue();
  requests++;
  return route.fulfill({ status: requests === 1 ? 429 : 410, contentType: 'application/json', headers: { 'Retry-After': '1' }, body: JSON.stringify({ message: 'Unavailable' }) });
 });
 await guest.locator('input[type=file]').setInputFiles(previewImage('limited.png'));
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByText('Too many uploads. Retry after 1 second.')).toBeVisible();
 await expect(guest.getByRole('button', { name: 'Retry failed photos' })).toBeDisabled();
 expect(requests).toBe(1);
 await guest.clock.fastForward(1100);
 await expect(guest.getByRole('button', { name: 'Retry failed photos' })).toBeEnabled();
 await guest.locator('input[type=file]').setInputFiles([previewImage('closed.png'), previewImage('pending.png', [110, 55, 75])]);
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByRole('status').filter({ hasText: '2 failed' })).toBeVisible();
 expect(requests).toBe(2);
 await expect(guest.getByRole('button', { name: 'Retry failed photos' })).toBeHidden();
 await expect(guest.getByText('This upload link has expired or closed.', { exact: true })).toHaveCount(2);
 await context.close();
});

test('server rejects invalid dimensions without retrying while other valid photos finish', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Validation recovery');
 const context = await browser.newContext();
 const guest = await context.newPage();
 await guest.goto(url);
 const image = new PNG({ width: 12001, height: 1 });
 image.data.fill(100);
 await guest.locator('input[type=file]').setInputFiles([
  { name: 'too-wide.png', mimeType: 'image/png', buffer: PNG.sync.write(image) }, previewImage('accepted.png')
 ]);
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByRole('status').filter({ hasText: '1 failed' })).toBeVisible();
 await expect(guest.getByText('Use valid JPEG, PNG or WebP images, up to 20 megapixels and 12,000 pixels per side.', { exact: true })).toBeVisible();
 await expect(guest.getByRole('button', { name: 'Retry failed photos' })).toBeHidden();
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(1);
 await context.close();
});

test('native form fallback uploads with a receipt when JavaScript is disabled', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Native upload');
 const context = await browser.newContext({ javaScriptEnabled: false });
 const guest = await context.newPage();
 await guest.goto(url);
 await guest.locator('input[type=file]').setInputFiles(previewImage('native.png'));
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByRole('status').filter({ hasText: 'Thank you! 1 photo(s) uploaded successfully.' })).toBeVisible();
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(1);
 await context.close();
});

async function galleryWithPhotos(page, browser, title) {
 const url = await createPreviewCollection(page, title);
 const collectionUrl = page.url();
 const context = await browser.newContext();
 const guest = await context.newPage();
 await guest.goto(url);
 await guest.locator('input[type=file]').setInputFiles([
  previewImage('green.png'), previewImage('red.png', [190, 80, 60]), previewImage('blue.png', [64, 110, 170])
 ]);
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByRole('status').filter({ hasText: 'Thank you!' })).toBeVisible();
 await context.close();
 await page.reload();
 await expect(page.locator('.photo-card')).toHaveCount(3);
 return collectionUrl;
}

test('gallery lightbox navigates with keyboard, traps focus and syncs photo selection', async ({ page, browser }) => {
 const errors = [];
 page.on('pageerror', error => errors.push(error.message));
 await galleryWithPhotos(page, browser, 'Lightbox memories');
 const openers = page.locator('[data-lightbox-photo]');
 const viewer = page.getByRole('dialog');
 const firstTitle = await openers.first().getAttribute('data-title');
 await openers.first().focus();
 await page.keyboard.press('Enter');
 await expect(viewer).toBeVisible();
 await expect(viewer.getByRole('heading')).toHaveText(firstTitle);
 await expect(viewer.locator('.lightbox-image')).toBeVisible();
 await expect(viewer.getByRole('button', { name: 'Close photo viewer' })).toBeFocused();
 for (let i = 0; i < 8; i++) {
  await page.keyboard.press('Tab');
  expect(await viewer.evaluate(element => element.contains(document.activeElement))).toBe(true);
 }
 await page.keyboard.press('ArrowLeft');
 await expect(viewer.locator('.lightbox-counter')).toHaveText('3 of 3 on this page');
 await page.keyboard.press('ArrowRight');
 await expect(viewer.locator('.lightbox-counter')).toHaveText('1 of 3 on this page');
 await viewer.getByRole('checkbox', { name: 'Select photo', exact: true }).check();
 await page.keyboard.press('Escape');
 await expect(viewer).not.toBeVisible();
 await expect(openers.first()).toBeFocused();
 await expect(page.locator('[data-selection-count]')).toHaveText('1 selected');
 await expect(page.getByRole('checkbox', { name: 'Select this page' })).toHaveJSProperty('indeterminate', true);
 await openers.first().click();
 await expect(viewer.getByRole('checkbox', { name: 'Select photo', exact: true })).toBeChecked();
 const downloadEvent = page.waitForEvent('download');
 await viewer.getByRole('link', { name: 'Download', exact: true }).click();
 expect((await downloadEvent).suggestedFilename()).toMatch(/^photo-\d+\.png$/);
 await page.screenshot({ path: 'screenshots/gallery-lightbox-desktop.png' });
 await viewer.getByRole('button', { name: 'Close photo viewer' }).click();
 await expect(viewer).not.toBeVisible();
 expect(errors).toEqual([]);
});

test('mobile gallery selects all, downloads ZIP and confirms only selected deletions', async ({ page, browser }) => {
 await page.setViewportSize({ width: 375, height: 812 });
 const collectionUrl = await galleryWithPhotos(page, browser, 'Selected memories');
 const selectAll = page.getByRole('checkbox', { name: 'Select this page' });
 await expect(page.getByRole('button', { name: 'Download ZIP' })).toBeDisabled();
 await selectAll.check();
 await expect(page.locator('[data-selection-count]')).toHaveText('3 selected');
 await expect(page.locator('.photo-card.is-selected')).toHaveCount(3);
 await page.getByRole('button', { name: 'Clear selection' }).click();
 await expect(page.locator('[data-selection-count]')).toHaveText('0 selected');
 await expect(selectAll).toBeFocused();
 const checks = page.locator('input[name="photos[]"]');
 await checks.nth(0).check();
 await checks.nth(2).check();
 const selectedIds = await checks.evaluateAll(elements => elements.filter(element => element.checked).map(element => element.value));
 await page.getByRole('button', { name: 'Download ZIP' }).click();
 await expect(page.getByRole('heading', { name: 'Your ZIP download.' })).toBeVisible();
 await expect(page.getByRole('link', { name: 'Download ready ZIP' })).toBeVisible({ timeout: 15000 });
 const exportUrl = page.url();
 const downloadEvent = page.waitForEvent('download');
 await page.getByRole('link', { name: 'Download ready ZIP' }).click();
 expect((await downloadEvent).suggestedFilename()).toMatch(/^collection-\d+-selected.zip$/);
 expect(page.url()).toBe(exportUrl);
 await page.screenshot({ path: 'screenshots/queued-zip-mobile.png', fullPage: true });
 await page.getByRole('link', { name: 'Back to gallery' }).click();
 expect(page.url()).toBe(collectionUrl);
 for (const id of selectedIds) await page.locator(`input[name="photos[]"][value="${id}"]`).check();
 await expect(page.locator('[data-selection-count]')).toHaveText('2 selected');
 expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await page.screenshot({ path: 'screenshots/gallery-selection-mobile.png', fullPage: true });
 await page.locator('[data-lightbox-photo]').first().click();
 const viewer = page.getByRole('dialog');
 await expect(viewer.locator('.lightbox-image')).toBeVisible();
 expect(await viewer.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
 await page.screenshot({ path: 'screenshots/gallery-lightbox-mobile.png' });
 await page.setViewportSize({ width: 812, height: 375 });
 expect(await viewer.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
 await viewer.getByRole('button', { name: 'Close photo viewer' }).click();
 await page.setViewportSize({ width: 375, height: 812 });
 page.once('dialog', dialog => dialog.dismiss());
 await page.getByRole('button', { name: 'Delete selected' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(3);
 await expect(checks.nth(0)).toBeChecked();
 page.once('dialog', async dialog => {
  expect(dialog.message()).toContain('2 selected photos');
  await dialog.accept();
 });
 await page.getByRole('button', { name: 'Delete selected' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(1);
 await expect(page.getByRole('status').filter({ hasText: '2 photos deleted.' })).toBeVisible();
 await expect(page.locator('[data-selection-count]')).toHaveText('0 selected');
});

test('lightbox reports failed images and a single photo remains usable; native gallery selection works', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'One photo');
 const collectionUrl = page.url();
 await page.goto(url);
 await page.locator('input[type=file]').setInputFiles(previewImage('only.png'));
 await page.getByRole('button', { name: 'Upload photos' }).click();
 await expect(page.getByRole('status').filter({ hasText: 'Thank you!' })).toBeVisible();
 await page.goto(collectionUrl);
 const opener = page.locator('[data-lightbox-photo]');
 const imageUrl = await opener.getAttribute('href');
 await page.route(`**${imageUrl}`, route => route.abort());
 await opener.click();
 const viewer = page.getByRole('dialog');
 await expect(viewer.getByRole('status')).toContainText('could not be loaded');
 await expect(viewer.getByRole('button', { name: 'Previous photo' })).toBeDisabled();
 await expect(viewer.getByRole('button', { name: 'Next photo' })).toBeDisabled();
 await viewer.getByRole('button', { name: 'Close photo viewer' }).click();
 await page.unroute(`**${imageUrl}`);
 await opener.click();
 await expect(viewer.locator('.lightbox-image')).toBeVisible();
 await page.keyboard.press('Escape');
 const native = await browser.newContext({ javaScriptEnabled: false, storageState: await page.context().storageState() });
 const nativePage = await native.newPage();
 await nativePage.goto(collectionUrl);
 await nativePage.locator('input[name="photos[]"]').check();
 await nativePage.getByRole('button', { name: 'Download ZIP' }).click();
 await expect(nativePage.getByRole('heading', { name: 'Your ZIP download.' })).toBeVisible();
 await expect.poll(async () => {
  const response = await nativePage.request.get(nativePage.url(), { headers: { Accept: 'application/json' } });
  return (await response.json()).data.status;
 }, { timeout: 15000 }).toBe('ready');
 await nativePage.reload();
 const downloadEvent = nativePage.waitForEvent('download');
 await nativePage.getByRole('link', { name: 'Download ready ZIP' }).click();
 expect((await downloadEvent).suggestedFilename()).toMatch(/selected\.zip$/);
 await nativePage.getByRole('link', { name: 'Back to gallery' }).click();
 await nativePage.locator('input[name="photos[]"]').check();
 await nativePage.getByRole('button', { name: 'Delete selected' }).click();
 await expect(nativePage.getByRole('heading', { name: 'Ready for the first photo' })).toBeVisible();
 await native.close();
});


test('real queue worker generates private bounded thumbnails while originals remain unchanged', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Queued thumbnails');
 const context = await browser.newContext();
 const guest = await context.newPage();
 const original = previewImage('large-original.png', [74, 124, 94], 1600, 1000);
 await guest.goto(url);
 await guest.locator('input[type=file]').setInputFiles(original);
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.getByRole('status').filter({ hasText: 'Thank you!' })).toBeVisible();
 await page.reload();
 const card = page.locator('.photo-card').first();
 const thumbnailUrl = await card.locator('img').getAttribute('src');
 expect(new URL(thumbnailUrl).pathname).toMatch(/\/photos\/\d+\/thumbnail$/);
 await expect.poll(async () => {
  const response = await page.request.get(thumbnailUrl);
  return response.headers()['content-type'];
 }, { timeout: 15000 }).toBe('image/jpeg');
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(card).toHaveAttribute('data-thumbnail-status', 'ready');
 await expect.poll(() => card.locator('img').evaluate(image => image.complete && image.naturalWidth)).toBe(480);
 expect(await card.locator('img').evaluate(image => image.naturalHeight)).toBe(300);
 const originalUrl = await card.locator('[data-lightbox-photo]').getAttribute('href');
 const response = await page.request.get(originalUrl);
 expect((await response.body()).toString('base64')).toBe(original.buffer.toString('base64'));
 expect((await context.request.get(thumbnailUrl, { maxRedirects: 0 })).status()).toBe(302);
 await card.locator('[data-lightbox-photo]').click();
 await expect(page.getByRole('dialog').locator('.lightbox-image')).toHaveAttribute('src', originalUrl);
 await page.keyboard.press('Escape');
 await page.screenshot({ path: 'screenshots/gallery-thumbnails-desktop.png', fullPage: true });
 page.once('dialog', dialog => dialog.accept());
 await card.getByRole('button', { name: 'Delete', exact: true }).click();
 expect((await page.request.get(thumbnailUrl)).status()).toBe(404);
 expect((await page.request.get(originalUrl)).status()).toBe(404);
 await context.close();
});


test('queued ZIP page shows processing and reconnects after status polling fails', async ({ page, browser }) => {
 await galleryWithPhotos(page, browser, 'ZIP status recovery');
 await page.getByRole('checkbox', { name: 'Select this page' }).check();
 let mode = 'processing';
 await page.route('**/exports/*', async route => {
  if (!route.request().headers()['accept']?.includes('application/json')) return route.continue();
  if (mode === 'offline') return route.abort();
  const response = await route.fetch();
  const body = await response.json();
  if (mode === 'processing') {
   body.data.status = 'processing';
   body.data.download_url = null;
  }
  await route.fulfill({ response, json: body });
 });
 await page.getByRole('button', { name: 'Download ZIP' }).click();
 await expect(page.getByRole('heading', { name: 'Building your ZIP' })).toBeVisible({ timeout: 10000 });
 await expect(page.getByRole('link', { name: 'Download ready ZIP' })).not.toBeVisible();
 await page.screenshot({ path: 'screenshots/queued-zip-processing-desktop.png', fullPage: true });
 mode = 'offline';
 await expect(page.locator('[data-export-connection]')).toContainText('Status could not be refreshed', { timeout: 10000 });
 mode = 'ready';
 await expect(page.getByRole('link', { name: 'Download ready ZIP' })).toBeVisible({ timeout: 10000 });
 await expect(page.locator('[data-export-connection]')).toBeEmpty();
 const downloadEvent = page.waitForEvent('download');
 await page.getByRole('link', { name: 'Download ready ZIP' }).click();
 expect((await downloadEvent).suggestedFilename()).toMatch(/selected\.zip$/);
 await page.getByRole('link', { name: 'Back to gallery' }).click();
 await expect(page.getByRole('region', { name: 'Recent ZIP requests' })).toBeVisible();
});


test('gallery receives new photos live, preserves selection and keeps the viewer open', async ({ page, browser }) => {
 const errors = []; page.on('pageerror', error => errors.push(error.message));
 const url = await createPreviewCollection(page, 'Live gallery');
 const context = await browser.newContext(); const guest = await context.newPage(); await guest.goto(url);
 await guest.locator('input[type=file]').setInputFiles(previewImage('live-first.png'));
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(1, { timeout: 15000 });
 await page.locator('input[name="photos[]"]').check();
 await guest.locator('input[type=file]').setInputFiles(previewImage('live-second.png', [200, 110, 20]));
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(2, { timeout: 15000 });
 await expect(page.locator('input[name="photos[]"]:checked')).toHaveCount(1);
 await page.locator('[data-lightbox-photo]').first().click();
 await guest.locator('input[type=file]').setInputFiles(previewImage('live-third.png', [30, 50, 90]));
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.locator('.upload-status')).toContainText('Thank you');
 await expect(page.locator('dialog')).toBeVisible();
 await page.getByRole('button', { name: 'Close photo viewer' }).click();
 await page.getByRole('heading', { name: 'Live gallery', exact: true }).click();
 await expect(page.locator('.photo-card')).toHaveCount(3, { timeout: 15000 });
 await page.getByLabel('Search name or photo ID').fill('live-second');
 await page.getByRole('button', { name: 'Apply filters' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(1);
 await expect(page.locator('.photo-filename')).toHaveText('live-second.png');
 await page.setViewportSize({ width: 390, height: 844 });
 await page.getByRole('link', { name: 'Tasks', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Background tasks' })).toBeVisible();
 expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await page.screenshot({ path: 'screenshots/tasks-mobile.png', fullPage: true });
 expect(errors).toEqual([]); await context.close();
});

test('optional compression resizes a photo and retries the exact same encoded bytes', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Compressed memories');
 const collectionUrl = page.url();
 const context = await browser.newContext(); const guest = await context.newPage(); await guest.goto(url);
 const image = new PNG({ width: 2400, height: 1200 });
 let seed = 12345;
 for (let i = 0; i < image.data.length; i += 4) {
  seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0;
  image.data[i] = seed & 255; image.data[i+1] = (seed >>> 8) & 255; image.data[i+2] = (seed >>> 16) & 255; image.data[i+3] = 255;
 }
 const original = PNG.sync.write(image);
 await guest.locator('input[type=file]').setInputFiles({ name: 'large.png', mimeType: 'image/png', buffer: original });
 await guest.locator('[data-compress]').check();
 const attempts = [];
 await guest.route('**/u/*', async route => {
  if (route.request().method() !== 'POST') { await route.continue(); return; }
  const data = route.request().postDataBuffer();
  // Multipart boundaries can change, but the encoded JPEG must stay identical.
  const begin = data.indexOf(Buffer.from([0xff, 0xd8, 0xff]));
  const end = data.lastIndexOf(Buffer.from([0xff, 0xd9]));
  expect(begin).toBeGreaterThan(0); attempts.push(data.subarray(begin, end + 2));
  if (attempts.length === 1) await route.fulfill({ status: 503, contentType: 'application/json', body: '{}' });
  else await route.continue();
 });
 await guest.getByRole('button', { name: 'Upload photos' }).click();
 await expect(guest.locator('.upload-status')).toContainText('Thank you', { timeout: 15000 });
 expect(attempts).toHaveLength(2); expect(attempts[0].equals(attempts[1])).toBe(true);
 expect(attempts[0].length).toBeLessThan(original.length);
 await expect(guest.locator('.photo-upload-state')).toContainText('reduced');
 await expect(guest.locator('[data-compress]')).toBeDisabled();
 await page.goto(collectionUrl);
 await expect(page.locator('.photo-info')).toContainText('2048 × 1024');
 const response = await page.request.get(await page.locator('[data-lightbox-photo]').getAttribute('href'));
 expect((await response.body()).equals(attempts[0])).toBe(true);
 await context.close();
});

test('organizer can protect QR upload with a password and revoke guest access', async ({ page, browser }) => {
 const url = await createPreviewCollection(page, 'Protected event');
 await page.getByLabel('Set upload password').fill('shared-secret-123');
 await page.getByRole('button', { name: 'Save settings' }).click();
 const context = await browser.newContext(); const guest = await context.newPage(); await guest.goto(url);
 await expect(guest.getByRole('heading', { name: 'Private upload link' })).toBeVisible();
 await guest.getByLabel('Upload password').fill('shared-secret-123');
 await guest.getByRole('button', { name: 'Unlock upload link' }).click();
 await expect(guest.getByRole('heading', { name: 'Protected event' })).toBeVisible();
 await page.getByLabel('Change upload password').fill('replacement-secret');
 await page.getByRole('button', { name: 'Save settings' }).click();
 await guest.reload();
 await expect(guest.getByRole('heading', { name: 'Private upload link' })).toBeVisible();
 await context.close();
});
