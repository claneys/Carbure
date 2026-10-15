// The portal installed as an app (Chrome/Edge "Install", Safari "Add to Dock", home
// screen): its Web App Manifest, its icons, and the installability seen by Chromium
const { test, expect, tr } = require('../fixtures');

test('the portal links a valid manifest, served with its media type', async ({ page, request }) => {
    await page.goto('/portal/');
    const href = await page.locator('link[rel="manifest"]').getAttribute('href');
    const manifestUrl = new URL(href, page.url()).href;

    const response = await request.get(manifestUrl);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toMatch(/^application\/manifest\+json\b/);
    const manifest = await response.json();
    expect(manifest).toMatchObject({ name: 'Carbure', short_name: 'Carbure', display: 'standalone' });
    // The start URL is the portal itself
    expect(new URL(manifest.start_url, manifestUrl).pathname).toBe('/portal/');

    for (const icon of manifest.icons) {
        const image = await request.get(new URL(icon.src, manifestUrl).href);
        expect(image.status(), icon.src).toBe(200);
        expect(image.headers()['content-type']).toBe('image/png');
    }
    const apple = await page.locator('link[rel="apple-touch-icon"]').getAttribute('href');
    expect((await request.get(new URL(apple, page.url()).href)).status()).toBe(200);
});

test('Chromium can install the portal as an app', async ({ adminPage: page }) => {
    const cdp = await page.context().newCDPSession(page);
    // The manifest is fetched and parsed by the browser itself
    await expect.poll(async () => (await cdp.send('Page.getAppManifest')).errors, { timeout: 10_000 }).toEqual([]);
    const { installabilityErrors } = await cdp.send('Page.getInstallabilityErrors');
    expect(installabilityErrors).toEqual([]);
});

test('the color of the app window follows the theme of the portal', async ({ userPage: page }) => {
    const colors = page.locator('meta[name="theme-color"]');
    await expect(colors).toHaveCount(2);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(colors.first()).toHaveAttribute('content', '#1a0b14');
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(colors.first()).toHaveAttribute('content', '#D12C48');
    await expect(colors.last()).toHaveAttribute('content', '#D12C48');
});

test('in the app window, logging out and in again stays in the portal', async ({ adminPage: page }) => {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('logout') }).click();
    await page.getByLabel(tr('username')).fill('admin');
    await page.getByLabel(tr('password')).fill('admin-password');
    await page.getByRole('button', { name: tr('loginButton') }).click();
    await expect(page.locator('.user-menu-name')).toHaveText('admin');
    // Still inside the scope of the app (./ of the manifest)
    expect(new URL(page.url()).pathname).toBe('/portal/');
});
