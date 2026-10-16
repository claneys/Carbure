// Accessibility beyond the contrasts (see accessibility.spec.js): the WCAG 2.2 A/AA rules
// of axe-core on every screen, the reflow at 320 CSS px (1.4.10), the zoom at 200 %
// (1.4.4) and the text spacing (1.4.12)
const { test, expect, tr, openTab, trustWoobRepository } = require('../fixtures');
const { axeViolations, reflowProblems, clippedTexts, TEXT_SPACING } = require('../axe');

test.describe.configure({ timeout: 180_000 });

test.beforeEach(async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
});

/** Tabs of the main navigation (administrators), then those of the Administration menu */
const MAIN_TABS = ['tabTransactions', 'tabBudget', 'tabInsights', 'tabTrends', 'tabRules', 'tabCategories', 'tabAccounts'];
const ADMIN_TABS = ['tabUsers', 'tabSync', 'tabAgents', 'tabLogs', 'tabSettings'];
/** Tabs of a user who is not an administrator */
const USER_TABS = ['tabTransactions', 'tabBudget', 'tabInsights', 'tabTrends'];

/**
 * Opens a tab and waits for its data
 * @param {import('@playwright/test').Page} page
 * @param {string} key - Translation key of the tab
 */
async function showTab(page, key) {
    if (ADMIN_TABS.includes(key)) {
        await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
        await page.getByRole('tab', { name: tr(key), exact: true }).click();
        await expect(page.getByRole('tab', { name: tr(key), exact: true })).toHaveAttribute('aria-selected', 'true');
    } else {
        await openTab(page, tr(key));
        await expect(page.locator('.tab-button.active')).toContainText(tr(key));
    }
    await page.waitForLoadState('networkidle');
    // The tabs are drawn again once the data is loaded
    await page.waitForTimeout(300);
}

/**
 * Runs a check on every tab of a list
 * @param {import('@playwright/test').Page} page
 * @param {string[]} tabs - Translation keys of the tabs
 * @param {(page, where: string) => Promise<string[]>} check
 * @returns {Promise<string[]>} The problems of every tab
 */
async function everyTab(page, tabs, check) {
    const problems = [];
    for (const key of tabs) {
        await showTab(page, key);
        problems.push(...await check(page, tr(key)));
    }
    return problems;
}

test('axe: the login screen', async ({ page }) => {
    await page.goto('/portal/');
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    expect(await axeViolations(page, 'login')).toEqual([]);
});

test('axe: every tab of an administrator', async ({ adminPage: page }) => {
    expect(await everyTab(page, [...MAIN_TABS, ...ADMIN_TABS], axeViolations)).toEqual([]);
});

test('axe: every tab of a user', async ({ userPage: page }) => {
    expect(await everyTab(page, USER_TABS, axeViolations)).toEqual([]);
});

test('axe: the menus and the modals', async ({ adminPage: page }) => {
    const problems = [];

    await page.locator('.user-menu-toggle').click();
    problems.push(...await axeViolations(page, 'user menu'));
    await page.keyboard.press('Escape');

    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    problems.push(...await axeViolations(page, 'administration menu'));
    await openTab(page, tr('tabTransactions'));

    await page.locator('.transaction-item').first().getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(detail).toBeVisible();
    problems.push(...await axeViolations(page, tr('transactionDetail')));
    await page.keyboard.press('Escape');
    await expect(detail).toHaveCount(0);

    await openTab(page, tr('tabBudget'));
    await page.getByRole('tab', { name: tr('budgetConfigTab') }).click();
    await page.locator('.budget-copy-button').click();
    const copy = page.getByRole('dialog', { name: tr('budgetCopyTitle') });
    await expect(copy).toBeVisible();
    problems.push(...await axeViolations(page, tr('budgetCopyTitle')));
    await page.keyboard.press('Escape');
    await expect(copy).toHaveCount(0);

    await openTab(page, tr('tabCategories'));
    await page.getByRole('button', { name: tr('addCategory') }).click();
    const category = page.getByRole('dialog', { name: tr('addCategory') });
    await expect(category).toBeVisible();
    problems.push(...await axeViolations(page, tr('addCategory')));
    await page.keyboard.press('Escape');
    await expect(category).toHaveCount(0);

    // Connecting a bank: its module installed once the woob repository is trusted
    trustWoobRepository();
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('connectBankTitle') }).click();
    const connect = page.getByRole('dialog', { name: tr('connectBankTitle') });
    await connect.getByLabel(new RegExp(tr('connectBankModule'))).selectOption('boursorama');
    await expect(connect.locator('fieldset.bank-setup input').first()).toBeVisible();
    await expect(connect.getByRole('button', { name: tr('bankConnect') })).toBeEnabled();
    problems.push(...await axeViolations(page, tr('connectBankTitle')));
    await page.keyboard.press('Escape');
    await expect(connect).toHaveCount(0);

    await page.getByRole('button', { name: tr('addAccountsTo', { bank: 'BNP Paribas (bnp)' }) }).click();
    const add = page.getByRole('dialog', { name: tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' }) });
    await expect(add.getByRole('checkbox')).toHaveCount(2);
    problems.push(...await axeViolations(page, tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' })));
    await page.keyboard.press('Escape');
    await expect(add).toHaveCount(0);

    await page.getByRole('button', { name: tr('renameBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) }).click();
    const rename = page.getByRole('dialog', { name: tr('renameBankConnection') });
    await expect(rename).toBeVisible();
    problems.push(...await axeViolations(page, tr('renameBankConnection')));
    await page.keyboard.press('Escape');
    await expect(rename).toHaveCount(0);

    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    problems.push(...await axeViolations(page, 'sync'));

    expect(problems).toEqual([]);
});

test('axe: the notifications of the profile and the result of the test email', async ({ adminPage: page }) => {
    const problems = [];

    // Profile: its sub-tabs; the notifications table, the email channel disabled with
    // its reason, the settings of the alerts under their label
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    problems.push(...await axeViolations(page, tr('profileTabProfile')));
    await page.getByRole('tab', { name: tr('myDevices'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('myDevices') })).toBeVisible();
    problems.push(...await axeViolations(page, tr('myDevices')));
    await page.getByRole('tab', { name: tr('notificationsTitle'), exact: true }).click();
    await expect(page.locator('#notifications-email-unavailable')).toBeVisible();
    problems.push(...await axeViolations(page, tr('notificationsTitle')));
    // At 320 CSS px wide too
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, tr('notificationsTitle')));
    await page.setViewportSize({ width: 1280, height: 720 });
    // The budget alert threshold refused: the field invalid, its error message
    await page.getByLabel(tr('budgetThreshold')).fill('250');
    await page.getByLabel(tr('budgetThreshold')).press('Enter');
    await expect(page.locator('#budget-threshold-error')).toBeVisible();
    problems.push(...await axeViolations(page, `${tr('budgetThreshold')} (error)`));
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, `${tr('budgetThreshold')} (error)`));
    await page.setViewportSize({ width: 1280, height: 720 });

    // Settings: the test email failed (no SMTP server configured)
    await showTab(page, 'tabSettings');
    await page.getByRole('button', { name: tr('smtpTestButton') }).click();
    await expect(page.locator('.smtp-test-result[role="alert"]')).toBeVisible();
    problems.push(...await axeViolations(page, tr('smtpTestTitle')));
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, tr('smtpTestTitle')));

    expect(problems).toEqual([]);
});

test('reflow: every tab at 320 CSS px wide, without horizontal scrolling', async ({ adminPage: page }) => {
    // 1280 px at 400 % (WCAG 1.4.10)
    await page.setViewportSize({ width: 320, height: 256 });
    expect(await everyTab(page, [...MAIN_TABS, ...ADMIN_TABS], reflowProblems)).toEqual([]);
});

test('zoom: every tab at 200 %, nothing cut nor scrolling horizontally', async ({ adminPage: page }) => {
    // The sizes of the portal are in CSS px: a zoom of 200 % of the browser on a 1280 px
    // screen lays the page out in 640 CSS px (WCAG 1.4.4)
    await page.setViewportSize({ width: 640, height: 400 });
    const check = async (page, where) => [...await reflowProblems(page, where), ...await clippedTexts(page, where)];
    expect(await everyTab(page, [...MAIN_TABS, ...ADMIN_TABS], check)).toEqual([]);
});

test('text spacing: no text cut with the spacing of WCAG 1.4.12', async ({ adminPage: page }) => {
    await page.addStyleTag({ content: TEXT_SPACING });
    expect(await everyTab(page, [...MAIN_TABS, ...ADMIN_TABS], clippedTexts)).toEqual([]);
});

test('reflow: a failed synchronization at 320 CSS px, its update of the bank modules included', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'), { timeout: 15_000 });
    await page.getByRole('button', { name: tr('syncShowLog') }).click();

    await page.setViewportSize({ width: 320, height: 256 });
    const update = page.locator('.sync-woob-help').getByRole('button', { name: tr('woobUpdateButton') });
    await update.scrollIntoViewIfNeeded();
    await expect(update).toBeVisible();
    expect([...await reflowProblems(page, 'sync'), ...await clippedTexts(page, 'sync')]).toEqual([]);
    expect(await axeViolations(page, 'sync 320')).toEqual([]);
});
