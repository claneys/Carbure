// Administration > API: the documentation of the API (Swagger UI of /swagger/), framed
// by the portal, authorized with the session of the administrator and in his theme
const { test, expect, tr } = require('../fixtures');

/**
 * Opens the API tab of the Administration menu
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').FrameLocator>} Swagger UI
 */
async function openApiDocs(page) {
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabApi'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('apiDocsTitle') })).toBeVisible();
    return page.frameLocator('iframe.api-docs-frame');
}

test('an administrator reads the documentation of the API in the portal', async ({ adminPage: page }) => {
    const swagger = await openApiDocs(page);

    // The frame has an accessible name; a link opens Swagger UI in a new tab
    const frame = page.locator('iframe.api-docs-frame');
    await expect(frame).toHaveAttribute('title', tr('apiDocsFrameTitle'));
    const link = page.getByRole('link', { name: new RegExp(tr('apiDocsOpen')) });
    await expect(link).toHaveAttribute('href', '../swagger/');
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener');

    // The operations described by swagger.php
    await expect(swagger.locator('.opblock-summary-path[data-path="/api/category"]').first()).toBeVisible({ timeout: 15_000 });
});

test('"Try it out" calls the API with the session of the portal, never a token in a URL', async ({ adminPage: page }) => {
    const swagger = await openApiDocs(page);
    const token = await page.evaluate(() => localStorage.getItem('authToken'));

    // Already authorized with the JWT of the portal
    await expect(swagger.locator('.btn.authorize')).toHaveClass(/\blocked\b/, { timeout: 15_000 });

    const operation = swagger.locator('.opblock-get').filter({ has: swagger.locator('.opblock-summary-path[data-path="/api/category"]') });
    await operation.locator('.opblock-summary').click();
    const response = page.waitForResponse(r => new URL(r.url()).pathname === '/api/category');
    await operation.getByRole('button', { name: 'Execute' }).click();
    const received = await response;
    const sent = received.request();
    expect(sent.headers()['authorization']).toBe(`Bearer ${token}`);
    expect(sent.url()).not.toContain(token);
    expect(received.status()).toBe(200);
    // The answer is shown below the operation
    await expect(operation.locator('.live-responses-table')).toContainText('200');
});

test('Swagger UI follows the theme of the portal', async ({ adminPage: page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    const swagger = await openApiDocs(page);
    const html = swagger.locator('html');
    await expect(swagger.locator('.opblock').first()).toBeVisible({ timeout: 15_000 });
    await expect(html).not.toHaveClass(/dark-mode/);

    // The header toggle: dark at once in the frame, then light again
    const toggle = page.locator('.header-user > button').first();
    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(html).toHaveClass(/dark-mode/);
    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(html).not.toHaveClass(/dark-mode/);
});

test('Swagger UI opened on its own follows the system theme', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/swagger/');
    await expect(page.locator('.swagger-ui .opblock').first()).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('html')).toHaveClass(/dark-mode/);
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('html')).not.toHaveClass(/dark-mode/);
});

test('a user who is not an administrator has no API tab', async ({ userPage: page }) => {
    await expect(page.locator('.transaction-item').first()).toBeVisible();
    await expect(page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true })).toHaveCount(0);
    await expect(page.locator('iframe.api-docs-frame')).toHaveCount(0);
});
