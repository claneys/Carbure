// Fixtures shared by the specs:
// - the database is recreated before each test (data set: server/seed.php);
// - an uncaught error in the page fails the test;
// - the confirmation dialogs (delete...) are accepted;
// - adminPage / userPage: the portal opened by a logged-in user (token from the API);
// - api: requests to the API as the administrator, to check what was saved.
const base = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

/** Translations of the portal (portal/i18n.js) */
const i18n = (() => {
    const source = fs.readFileSync(path.join(__dirname, '../../portal/i18n.js'), 'utf8');
    const context = { localStorage: { getItem: () => null, setItem: () => {} }, navigator: { language: 'fr' } };
    vm.runInNewContext(source + '\nthis.i18n = i18n;', context);
    return context.i18n;
})();

/**
 * Text of the portal for a translation key, like t() of the portal
 * @param {string} key - Key (nested keys with dots)
 * @param {Object} params - Values of the {placeholders}
 * @param {string} locale - fr or en
 * @returns {string}
 */
function tr(key, params = {}, locale = 'fr') {
    const text = key.split('.').reduce((value, part) => value && value[part], i18n[locale]);
    if (typeof text !== 'string') {
        throw new Error(`Unknown translation key: ${key}`);
    }
    return text.replace(/\{(\w+)\}/g, (match, name) => (name in params ? String(params[name]) : match));
}

/** Users of the data set (server/seed.php) */
const USERS = {
    admin: { username: 'admin', password: 'admin-password' },
    marie: { username: 'marie', password: 'marie-password' },
};

/**
 * Logs in through the API
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} user - Key of USERS
 * @returns {Promise<string>} JWT
 */
async function apiLogin(request, user) {
    const response = await request.post('/api/user/login', { data: USERS[user] });
    base.expect(response.ok()).toBeTruthy();
    return (await response.json()).token;
}

/**
 * Opens the portal as a logged-in user (token stored like the login screen does)
 * @param {import('@playwright/test').Page} page
 * @param {string} token
 * @param {string} username
 */
async function openPortal(page, token, username) {
    await page.addInitScript(([token, username]) => {
        if (!sessionStorage.getItem('e2e-init')) {
            sessionStorage.setItem('e2e-init', '1');
            localStorage.setItem('authToken', token);
            localStorage.setItem('username', username);
        }
    }, [token, username]);
    await page.goto('/portal/');
    await base.expect(page.locator('.user-menu-name')).toHaveText(username);
}

const test = base.test.extend({
    resetDatabase: [async ({ request }, use) => {
        const response = await request.get('/api/__e2e/reset');
        base.expect(response.ok(), await response.text()).toBeTruthy();
        await use();
    }, { auto: true }],

    page: async ({ page }, use) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => dialog.accept());
        await use(page);
        base.expect(errors, 'Uncaught errors in the page').toEqual([]);
    },

    adminPage: async ({ page, request }, use) => {
        await openPortal(page, await apiLogin(request, 'admin'), 'admin');
        await use(page);
    },

    userPage: async ({ page, request }, use) => {
        await openPortal(page, await apiLogin(request, 'marie'), 'marie');
        await use(page);
    },

    api: async ({ playwright, baseURL }, use) => {
        const anonymous = await playwright.request.newContext({ baseURL });
        const token = await apiLogin(anonymous, 'admin');
        await anonymous.dispose();
        const api = await playwright.request.newContext({ baseURL, extraHTTPHeaders: { Authorization: `Bearer ${token}` } });
        await use(api);
        await api.dispose();
    },
});

/** Tabs grouped under "Administration" (see ADMINISTRATION_TABS in portal/app.js) */
const ADMINISTRATION_TABS = ['tabUsers', 'tabLogs', 'tabAgents'];

/**
 * Opens a tab of the main navigation; the tabs of the Administration menu through it
 * (administrators) or, for the AI agent tab of the other users, through the user menu
 * @param {import('@playwright/test').Page} page
 * @param {string} name - Label of the tab
 */
async function openTab(page, name) {
    if (!ADMINISTRATION_TABS.map(key => tr(key)).includes(name)) {
        await page.locator('.tabs').getByRole('button', { name, exact: true }).click();
        return;
    }
    const administration = page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true });
    if (await administration.count()) {
        await administration.click();
        await page.getByRole('tab', { name, exact: true }).click();
    } else {
        await page.locator('.user-menu-toggle').click();
        await page.getByRole('menuitem', { name }).click();
    }
}

/**
 * Chooses a category in a category picker (components/CategoryPicker.js)
 * @param {import('@playwright/test').Locator} scope - Element containing the picker
 * @param {string} name - Name of the category
 */
async function pickCategory(scope, name) {
    await scope.locator('.category-picker-button').click();
    await scope.locator('.category-picker-search input').fill(name);
    await scope.locator('.category-picker-option').filter({ has: scope.page().locator('.category-picker-name', { hasText: new RegExp(`(^|›)\\s*${name}\\s*$`) }) }).first().click();
}

/**
 * Amount as shown by the portal in French (narrow no-break space, comma)
 * @param {number} value
 * @returns {string}
 */
function euros(value) {
    return new Intl.NumberFormat('fr', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) + ' €';
}

/**
 * Month and year of the current month, or of a previous one, as the API expects them
 * @param {number} ago - Number of months before the current one
 * @returns {{month: number, year: number}}
 */
function currentMonth(ago = 0) {
    const date = new Date();
    date.setDate(1);
    date.setMonth(date.getMonth() - ago);
    return { month: date.getMonth() + 1, year: date.getFullYear() };
}

/**
 * Text to match literally in a regular expression (every special character escaped)
 * @param {string} text
 * @returns {string}
 */
function escapeRegExp(text) {
    return String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * The repository of the fake woob trusted, as after an update confirmed by an
 * administrator (the keyrings of the fake woob are in the temporary directory, shared with
 * the server; the reset of the database forgets them)
 */
function trustWoobRepository() {
    const os = require('os');
    const keyrings = path.join(os.tmpdir(), 'carbure-fake-woob-keyrings');
    fs.mkdirSync(keyrings, { recursive: true });
    fs.copyFileSync(path.join(__dirname, '../fixtures/woob-repository/trusted.gpg'), path.join(keyrings, 'https___updates_woob_tech_3_main_.gpg'));
}

module.exports = { test, expect: base.expect, USERS, openTab, tr, pickCategory, euros, currentMonth, escapeRegExp, trustWoobRepository };
