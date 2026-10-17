// Login screen, logout, expired session and the tabs of each role
const { test, expect, tr } = require('../fixtures');

const ADMIN_TABS = ['tabRules', 'tabCategories', 'tabAccounts', 'tabAdministration'];
const USER_TABS = ['tabTransactions', 'tabBudget', 'tabInsights', 'tabTrends'];

test('logs in and out from the login screen', async ({ page }) => {
    await page.goto('/portal/');
    // The API is found from the address of the portal: no field to type it
    await expect(page.locator('#apiUrl')).toHaveCount(0);

    await page.getByLabel(tr('username')).fill('admin');
    await page.getByLabel(tr('password')).fill('admin-password');
    await page.getByRole('button', { name: tr('loginButton') }).click();

    await expect(page.locator('.user-menu-name')).toHaveText('admin');
    await expect(page.locator('.transaction-item').first()).toBeVisible();

    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('logout') }).click();
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('authToken'))).toBeNull();
});

test('refuses a wrong password', async ({ page }) => {
    await page.goto('/portal/');

    await page.getByLabel(tr('username')).fill('admin');
    await page.getByLabel(tr('password')).fill('wrong-password');
    await page.getByRole('button', { name: tr('loginButton') }).click();

    await expect(page.getByRole('alert')).toContainText(tr('invalidCredentials'));
    await expect(page.locator('.user-menu-name')).toHaveCount(0);
});

test('tells at once that the account is locked after 5 failed logins', async ({ page }) => {
    await page.goto('/portal/');
    await page.getByLabel(tr('username')).fill('marie');
    await page.getByLabel(tr('password')).fill('wrong-password');
    for (let i = 1; i < 5; i++) {
        await page.getByRole('button', { name: tr('loginButton') }).click();
        await expect(page.getByRole('alert')).toContainText(tr('invalidCredentials'));
    }
    // The fifth failure locks the account: the login screen says so, not "wrong password"
    await page.getByRole('button', { name: tr('loginButton') }).click();
    await expect(page.getByRole('alert')).toContainText(tr('accountLocked'));

    // Even the right password is refused, with the same message
    await page.getByLabel(tr('password')).fill('marie-password');
    await page.getByRole('button', { name: tr('loginButton') }).click();
    await expect(page.getByRole('alert')).toContainText(tr('accountLocked'));
    await expect(page.locator('.user-menu-name')).toHaveCount(0);
});

test('goes back to the login screen when the session has expired', async ({ page }) => {
    await page.addInitScript(() => {
        localStorage.setItem('authToken', 'expired.token.value');
        // Left by an older version of the login screen: ignored and removed
        localStorage.setItem('apiUrl', 'https://elsewhere.invalid');
    });
    await page.goto('/portal/');

    await expect(page.getByRole('alert')).toContainText(tr('sessionExpired'));
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('apiUrl'))).toBeNull();
});

test('shows every tab to an administrator', async ({ adminPage: page }) => {
    for (const key of [...USER_TABS, ...ADMIN_TABS]) {
        await expect(page.locator('.tabs').getByRole('button', { name: tr(key), exact: true })).toBeVisible();
    }
});

test('groups the users, the logs, the settings, the sync, the AI agent and the backup under Administration', async ({ adminPage: page }) => {
    const tabs = page.locator('.tabs');
    for (const key of ['tabUsers', 'tabLogs', 'tabSettings', 'tabSync', 'tabAgents', 'tabBackup']) {
        await expect(tabs.getByRole('button', { name: tr(key), exact: true })).toHaveCount(0);
    }

    await tabs.getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    const sections = page.getByRole('tablist', { name: tr('tabAdministration') });
    await expect(sections.getByRole('tab')).toHaveText([tr('tabUsers'), tr('tabSync'), tr('tabAgents'), tr('tabLogs'), tr('tabSettings'), tr('tabBackup'), tr('tabApi')].map(label => new RegExp(`${label}$`)));
    await expect(sections.getByRole('tab', { name: tr('tabUsers') })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('heading', { name: tr('usersTitle') })).toBeVisible();

    await sections.getByRole('tab', { name: tr('tabAgents') }).click();
    await expect(page.getByRole('heading', { name: tr('apiTokensTitle') })).toBeVisible();
    await expect(tabs.locator('.tab-button.active')).toHaveAccessibleName(tr('tabAdministration'));

    // Back to the last section opened
    await tabs.getByRole('button', { name: tr('tabTransactions'), exact: true }).click();
    await expect(sections).toHaveCount(0);
    await tabs.getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await expect(sections.getByRole('tab', { name: tr('tabAgents') })).toHaveAttribute('aria-selected', 'true');
});

test('a user opens the AI agent tab from the user menu', async ({ userPage: page }) => {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('tabAgents') }).click();

    await expect(page.getByRole('heading', { name: tr('apiTokensTitle') })).toBeVisible();
    await expect(page.getByRole('tablist', { name: tr('tabAdministration') })).toHaveCount(0);
});

test('hides the administration tabs from a user', async ({ userPage: page }) => {
    for (const key of USER_TABS) {
        await expect(page.locator('.tabs').getByRole('button', { name: tr(key), exact: true })).toBeVisible();
    }
    for (const key of ADMIN_TABS) {
        await expect(page.locator('.tabs').getByRole('button', { name: tr(key), exact: true })).toHaveCount(0);
    }
});

test('the logo brings back the transactions of the current month', async ({ adminPage: page }) => {
    await page.locator('.tabs').getByRole('button', { name: tr('tabTrends'), exact: true }).click();
    await page.locator('#month').selectOption({ index: 0 });

    await page.locator('.app-home').click();

    await expect(page.locator('.tabs .tab-button.active')).toHaveAccessibleName(tr('tabTransactions'));
    await expect(page.locator('#month')).toHaveValue(String(new Date().getMonth() + 1));
});
