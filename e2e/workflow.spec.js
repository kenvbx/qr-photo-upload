import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import jsQR from 'jsqr';
async function login(page) {
 await page.goto('/login');
 await page.getByLabel('Email address').fill('organizer@example.test');
 await page.getByLabel('Password', { exact: true }).fill('local-demo-photos-2026');
 await page.getByRole('button', { name: 'Sign in to workspace' }).click();
 await expect(page.getByRole('heading', { name: 'Your collections.' })).toBeVisible();
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

function previewImage(name, color = [74, 124, 94]) {
 const image = new PNG({ width: 80, height: 60 });
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
 await expect(guest.getByRole('status').filter({ hasText: 'Thank you! 2 photo(s)' })).toBeVisible();
 expect(uploadRequests).toHaveLength(1);
 await page.getByRole('link', { name: 'Refresh gallery' }).click();
 await expect(page.locator('.photo-card')).toHaveCount(2);
 const saved = [];
 for (const image of await page.locator('.photo-card img').all()) {
  const response = await page.request.get(await image.getAttribute('src'));
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
