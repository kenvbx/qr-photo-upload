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
