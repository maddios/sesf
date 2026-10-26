import type { Page } from '@playwright/test';
import { test, expect, visit, login, shot } from './support/test';
import { seed } from './support/seed';

/** A small real JPEG, drawn on a canvas in the browser (no fixture file needed). */
async function makeJpeg(page: Page): Promise<Buffer> {
  const dataUrl = await page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = 640;
    canvas.height = 400;
    const ctx = canvas.getContext('2d')!;
    const gradient = ctx.createLinearGradient(0, 0, 640, 400);
    gradient.addColorStop(0, '#0b3d91');
    gradient.addColorStop(1, '#5fa8d3');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 640, 400);
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 48px sans-serif';
    ctx.fillText('E2E Shark', 180, 210);
    return canvas.toDataURL('image/jpeg', 0.85);
  });
  return Buffer.from(dataUrl.split(',')[1], 'base64');
}

test.describe('garage', () => {
  test('adds a car with a photo, shows it in the garage, edits it, and marks it sold', async ({ page }) => {
    const user = seed().users.garage;
    const nickname = `Shark${Date.now().toString(36).slice(-6)}`;
    await login(page, user.email);

    await visit(page, `${user.profilePath}/cars`);
    await expect(page.getByRole('heading', { level: 1, name: 'Garage' })).toBeVisible();
    await page.getByRole('link', { name: 'Add car' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Add a car' })).toBeVisible();

    await page.getByRole('textbox', { name: 'Nickname' }).fill(nickname);
    await page.getByRole('combobox', { name: 'Body style' }).selectOption({ label: 'e24' });
    const type = page.getByRole('combobox', { name: 'Type' });
    await expect(type.getByRole('option', { name: 'Car', exact: true })).toBeAttached();
    await type.selectOption({ label: 'Car' });
    await page.getByRole('textbox', { name: 'Year' }).fill('1988');
    await page.getByRole('textbox', { name: 'Model' }).fill('635CSi');
    await page.getByRole('button', { name: 'Add car and continue' }).click();

    await expect(page).toHaveURL(/#\/car\/\d+\/new-image$/);
    const carId = Number(page.url().match(/#\/car\/(\d+)\/new-image/)![1]);
    await expect(page.getByRole('heading', { level: 1, name: 'Add a photo' })).toBeVisible();
    await page.locator('#car-image-file').setInputFiles({ name: 'shark.jpg', mimeType: 'image/jpeg', buffer: await makeJpeg(page) });
    const upload = page.getByRole('button', { name: 'Upload photo' });
    await expect(upload).toBeEnabled();
    const [uploadResponse] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/car/profile-image') && r.request().method() === 'POST'),
      upload.click(),
    ]);
    expect(uploadResponse.status()).toBe(200);
    await expect(page.getByText('The photo was uploaded.')).toBeVisible();
    await shot(page, '06-car-photo');

    // The garage lists the car with its photo, served from the sandbox file store.
    await visit(page, `${user.profilePath}/cars`);
    const garage = page.getByRole('list', { name: 'Cars in the garage' });
    await expect(garage.getByRole('heading', { name: nickname })).toBeVisible();
    const photo = garage.getByRole('img', { name: `Photo of ${nickname}` });
    await expect(photo).toBeVisible();
    await expect.poll(() => photo.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    await shot(page, '06-garage');

    // Edit: rename the car.
    await page.getByRole('link', { name: `Edit ${nickname}` }).click();
    await expect(page).toHaveURL(new RegExp(`#/car/${carId}/settings$`));
    const renamed = `${nickname}X`;
    await expect(page.getByRole('textbox', { name: 'Nickname' })).toHaveValue(nickname);
    await page.getByRole('textbox', { name: 'Nickname' }).fill(renamed);
    const [save] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/car/profile') && r.request().method() === 'PUT'),
      page.getByRole('button', { name: 'Save car' }).click(),
    ]);
    expect(save.status()).toBe(200);
    await visit(page, `${user.profilePath}/cars`);
    await expect(garage.getByRole('heading', { name: renamed })).toBeVisible();
    // Updated in place: still one car in the garage, under the same id.
    await expect(garage.getByRole('heading', { level: 2 })).toHaveCount(1);
    await expect(garage.getByRole('link', { name: renamed, exact: true })).toHaveAttribute('href', `#/car/${carId}`);

    // Mark as sold: confirm in the dialog; the car leaves the garage but keeps its public page.
    await page.getByRole('button', { name: `Mark as sold ${renamed}` }).click();
    const dialog = page.getByRole('dialog', { name: 'Mark this car as sold?' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Mark as sold' }).click();
    await expect(dialog).toBeHidden();
    await expect(garage.getByRole('heading', { name: renamed })).toHaveCount(0);
    await expect(page.getByText(/no cars|empty/i).or(page.getByRole('link', { name: 'Add car' })).first()).toBeVisible();
    // The public car page stays, without an owner and without the owner's edit link.
    await visit(page, `/car/${carId}`);
    await expect(page.getByRole('main').getByRole('heading', { level: 1, name: '1988 635CSi' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Edit car' })).toHaveCount(0);
    // The uploaded photo is shown on the public page too (its URL carries a cache-busting query).
    const publicPhoto = page.getByRole('main').getByRole('img', { name: 'Photo of 1988 635CSi' });
    await expect(publicPhoto).toBeVisible();
    await expect.poll(() => publicPhoto.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    await expect(page.getByText('Owner:')).toHaveCount(0);
  });
});
