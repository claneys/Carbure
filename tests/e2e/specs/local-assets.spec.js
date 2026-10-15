// No external CDN: the portal and Swagger UI only request their own origin
const { test, expect, openTab, tr } = require('../fixtures');

/**
 * Records the requests of the page that go to another origin
 * @param {import('@playwright/test').Page} page
 * @param {string} origin - Origin of the portal
 * @returns {string[]} The URLs requested elsewhere (filled while the page runs)
 */
function externalRequests(page, origin) {
    const external = [];
    page.on('request', request => {
        const url = request.url();
        if (!url.startsWith(origin + '/') && !url.startsWith('data:') && !url.startsWith('blob:')) {
            external.push(url);
        }
    });
    return external;
}

test('the portal loads everything from its own origin', async ({ adminPage: page, baseURL }) => {
    const external = externalRequests(page, new URL(baseURL).origin);
    await page.reload();
    await expect(page.locator('.user-menu-name')).toHaveText('admin');
    for (const tab of ['tabBudget', 'tabTrends', 'tabAccounts']) {
        await openTab(page, tr(tab));
    }
    expect(external).toEqual([]);
});

test('Swagger UI loads everything from its own origin', async ({ page, baseURL }) => {
    const external = externalRequests(page, new URL(baseURL).origin);
    await page.goto('/swagger/index.html');
    // The operations of the API, described by swagger.php
    await expect(page.locator('.opblock-summary').first()).toBeVisible({ timeout: 15_000 });
    // No badge of validator.swagger.io
    await expect(page.locator('.validator-badge, img[src*="validator"]')).toHaveCount(0);
    expect(external).toEqual([]);
});
