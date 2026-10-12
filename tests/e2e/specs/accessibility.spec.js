// Accessibility: every text of the portal is readable (WCAG 2.2 AA contrast), the
// buttons at rest and on hover, on every tab and in the modals (see a11y.js)
const { test, expect, tr, openTab } = require('../fixtures');
const { contrastProblems } = require('../a11y');

// Hovering every button of every screen takes time
test.describe.configure({ timeout: 180_000 });

test.beforeEach(async ({ page }) => {
    // No transition: the colors are measured once the hover is applied
    await page.emulateMedia({ reducedMotion: 'reduce' });
});

test('the login screen is readable', async ({ page }) => {
    await page.goto('/portal/');
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    expect(await contrastProblems(page, 'login')).toEqual([]);
});

test('every tab is readable, buttons on hover included', async ({ adminPage: page }) => {
    const problems = [];
    const tabs = ['tabTransactions', 'tabBudget', 'tabInsights', 'tabTrends', 'tabRules', 'tabCategories', 'tabAccounts'];
    for (const tab of tabs) {
        await openTab(page, tr(tab));
        await expect(page.locator('.tab-button.active')).toContainText(tr(tab));
        await page.waitForLoadState('networkidle');
        // The tabs are drawn again once the data is loaded
        await page.waitForTimeout(300);
        problems.push(...await contrastProblems(page, tr(tab)));
    }

    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    for (const tab of ['tabUsers', 'tabSync', 'tabAgents', 'tabLogs', 'tabSettings']) {
        await page.getByRole('tab', { name: tr(tab), exact: true }).click();
        await expect(page.getByRole('tab', { name: tr(tab), exact: true })).toHaveAttribute('aria-selected', 'true');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);
        problems.push(...await contrastProblems(page, tr(tab)));
    }

    expect(problems).toEqual([]);
});

test('the menus, modals and the synchronization log are readable', async ({ adminPage: page }) => {
    const problems = [];

    await page.locator('.user-menu-toggle').click();
    problems.push(...await contrastProblems(page, 'user menu'));
    await page.keyboard.press('Escape');

    await page.locator('.transaction-item').first().getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(detail).toBeVisible();
    problems.push(...await contrastProblems(page, tr('transactionDetail')));
    await page.keyboard.press('Escape');
    await expect(detail).toHaveCount(0);

    await openTab(page, tr('tabCategories'));
    await page.getByRole('button', { name: tr('addCategory') }).click();
    const category = page.getByRole('dialog', { name: tr('addCategory') });
    await expect(category).toBeVisible();
    problems.push(...await contrastProblems(page, tr('addCategory')));
    await page.keyboard.press('Escape');
    await expect(category).toHaveCount(0);

    // A synchronization in error, with its log open
    await openTab(page, tr('tabAccounts'));
    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    problems.push(...await contrastProblems(page, 'sync'));

    expect(problems).toEqual([]);
});
