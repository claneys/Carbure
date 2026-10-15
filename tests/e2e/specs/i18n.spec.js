// Languages of the portal: the texts of the language (portal/i18n/<locale>.json) are
// loaded before the page is shown, <html lang> follows it, plurals by Intl.PluralRules
const { test, expect, tr } = require('../fixtures');

/**
 * Records the translation files requested by the page
 * @param {import('@playwright/test').Page} page
 * @returns {string[]} The languages requested (filled while the page runs)
 */
function translationRequests(page) {
    const requested = [];
    page.on('request', request => {
        const match = new URL(request.url()).pathname.match(/\/portal\/i18n\/(\w+)\.json$/);
        if (match) {
            requested.push(match[1]);
        }
    });
    return requested;
}

/**
 * Chooses the language of the profile and saves it (the page reloads in that language)
 * @param {import('@playwright/test').Page} page
 * @param {string} from - Language shown
 * @param {string} to - Language chosen
 */
async function chooseLanguage(page, from, to) {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile', {}, from) }).click();
    await page.getByLabel(tr('language', {}, from)).selectOption(to);
    await page.getByRole('button', { name: tr('save', {}, from) }).click();
}

test('switches the language: the other file is loaded and <html lang> follows', async ({ userPage: page }) => {
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    const requested = translationRequests(page);

    await chooseLanguage(page, 'fr', 'en');
    await expect(page.locator('.tabs').getByRole('button', { name: tr('tabTransactions', {}, 'en'), exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    // English, and French as the fallback of a missing key
    expect([...new Set(requested)].sort()).toEqual(['en', 'fr']);

    // The plural forms of English
    await page.locator('.tabs').getByRole('button', { name: tr('tabTransactions', {}, 'en'), exact: true }).click();
    await expect(page.locator('.transaction-count')).toHaveText('6 transactions');
    await page.getByRole('searchbox', { name: tr('searchPlaceholder', {}, 'en') }).fill('PETIT ZINC');
    await expect(page.locator('.transaction-count')).toHaveText('1 transaction');
    await expect(page.locator('.search-total')).toContainText('Total of the 1 transaction found');
    await page.getByRole('button', { name: tr('clearSearch', {}, 'en') }).click();

    requested.length = 0;
    await chooseLanguage(page, 'en', 'fr');
    await expect(page.locator('.tabs').getByRole('button', { name: tr('tabTrends'), exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    // French only: it is the reference language
    expect([...new Set(requested)]).toEqual(['fr']);
});

test('the translation files are versioned against stale caches', async ({ page }) => {
    const urls = [];
    page.on('request', request => {
        if (request.url().includes('/portal/i18n/')) {
            urls.push(request.url());
        }
    });
    await page.goto('/portal/');
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    expect(urls.length).toBeGreaterThan(0);
    for (const url of urls) {
        expect(url).toMatch(/\/portal\/i18n\/fr\.json\?v=[\w.+-]+$/);
    }
});

test('the translation files are served as JSON', async ({ request }) => {
    for (const locale of ['fr', 'en']) {
        const response = await request.get(`/portal/i18n/${locale}.json`);
        expect(response.ok()).toBeTruthy();
        expect(response.headers()['content-type']).toContain('application/json');
        expect((await response.json()).appTitle).toBe('Carbure');
    }
});

test('shows the singular or the plural form of a count', async ({ adminPage: page }) => {
    await expect(page.locator('.transaction-count')).toHaveText('6 transactions');

    await page.getByRole('searchbox', { name: tr('searchPlaceholder') }).fill('PETIT ZINC');
    await expect(page.locator('.transaction-item')).toHaveCount(1);
    await expect(page.locator('.transaction-count')).toHaveText('1 transaction');
    await expect(page.locator('.search-total')).toContainText('Total de 1 transaction trouvée');
});

test('a translation file that cannot be loaded falls back to French', async ({ adminPage: page }) => {
    await page.route('**/portal/i18n/en.json*', route => route.fulfill({ status: 500, body: 'error' }));
    await page.evaluate(() => localStorage.setItem('locale', 'en'));
    await page.reload();
    await expect(page.locator('.tabs').getByRole('button', { name: tr('tabTrends'), exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
});

test('shows a message when no translation can be loaded', async ({ page }) => {
    await page.route('**/portal/i18n/*.json*', route => route.fulfill({ status: 500, body: 'error' }));
    await page.goto('/portal/');
    await expect(page.getByRole('alert')).toContainText('The portal could not be loaded');
    await expect(page.locator('#app')).toBeHidden();
});
