// Accessibility: every text of the portal is readable (WCAG 2.2 AA contrast), the
// buttons at rest and on hover, on every tab and in the modals (see a11y.js)
const { test, expect, tr, openTab, trustWoobRepository } = require('../fixtures');
const { contrastProblems } = require('../a11y');
const path = require('path');

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

test('the accounts grouped under their bank are readable', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    // A bank with an account, a bank without (its hint), the other accounts
    await expect(page.locator('.bank-group')).toHaveCount(3);
    await expect(page.locator('.bank-group-empty')).toBeVisible();
    await expect(page.locator('.bank-group-others')).toBeVisible();
    expect(await contrastProblems(page, tr('tabAccounts'))).toEqual([]);
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

    // Preview of the import of a statement file read with the profile of its bank
    await page.getByRole('button', { name: tr('importButton') }).click();
    const importModal = page.getByRole('dialog', { name: tr('importTitle') });
    await importModal.locator('input[type="file"]').setInputFiles(path.join(__dirname, '../../fixtures/import/boursorama.csv'));
    await expect(importModal.locator('.import-bank')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('importTitle')));
    await page.keyboard.press('Escape');
    await expect(importModal).toHaveCount(0);

    // Copy of the budgets of the previous month (Budgets view)
    await openTab(page, tr('tabBudget'));
    await page.getByRole('tab', { name: tr('budgetConfigTab') }).click();
    await page.locator('.budget-copy-button').click();
    const copy = page.getByRole('dialog', { name: tr('budgetCopyTitle') });
    await expect(copy).toBeVisible();
    problems.push(...await contrastProblems(page, tr('budgetCopyTitle')));
    await page.keyboard.press('Escape');
    await expect(copy).toHaveCount(0);

    await openTab(page, tr('tabCategories'));
    await page.getByRole('button', { name: tr('addCategory') }).click();
    const category = page.getByRole('dialog', { name: tr('addCategory') });
    await expect(category).toBeVisible();
    problems.push(...await contrastProblems(page, tr('addCategory')));
    await page.keyboard.press('Escape');
    await expect(category).toHaveCount(0);

    // Accounts: update of the bank modules, the fingerprint of the woob repository to compare
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('woobUpdateButton') }).click();
    const woobUpdate = page.getByRole('dialog', { name: tr('woobUpdateTitle') });
    await expect(woobUpdate.getByRole('button', { name: tr('woobTrustAndUpdate') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('woobUpdateTitle')));
    await page.keyboard.press('Escape');
    await expect(woobUpdate).toHaveCount(0);

    // Connecting a bank (its settings asked, its module installed once the woob repository
    // is trusted), then the accounts woob lists for one
    trustWoobRepository();
    await page.getByRole('button', { name: tr('connectBankTitle') }).click();
    const connect = page.getByRole('dialog', { name: tr('connectBankTitle') });
    await connect.getByLabel(new RegExp(tr('connectBankModule'))).selectOption('boursorama');
    await expect(connect.locator('fieldset.bank-setup input').first()).toBeVisible();
    // Measured once the settings are loaded: the connect button is enabled (not mid-change)
    await expect(connect.getByRole('button', { name: tr('bankConnect') })).toBeEnabled();
    problems.push(...await contrastProblems(page, tr('connectBankTitle')));
    await page.keyboard.press('Escape');
    await expect(connect).toHaveCount(0);

    await page.getByRole('button', { name: tr('addAccountsTo', { bank: 'BNP Paribas (bnp)' }) }).click();
    const add = page.getByRole('dialog', { name: tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' }) });
    await expect(add.getByRole('checkbox')).toHaveCount(2);
    problems.push(...await contrastProblems(page, tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' })));
    await page.keyboard.press('Escape');
    await expect(add).toHaveCount(0);

    await page.getByRole('button', { name: tr('renameBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) }).click();
    const rename = page.getByRole('dialog', { name: tr('renameBankConnection') });
    await expect(rename).toBeVisible();
    problems.push(...await contrastProblems(page, tr('renameBankConnection')));
    await page.keyboard.press('Escape');
    await expect(rename).toHaveCount(0);

    // Confirmation of the removal of a bank: at rest, then its action running (the
    // request held, its button with a loader), then failed (the error in the modal)
    let release;
    const held = new Promise(resolve => { release = resolve; });
    await page.route('**/api/bank/backend?backend=bnp', async route => {
        await held;
        await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'woob could not remove the bank bnp', code: 500 }) });
    });
    await page.getByRole('button', { name: tr('deleteBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) }).click();
    const confirmRemoval = page.getByRole('dialog', { name: tr('deleteBankConnection') });
    await expect(confirmRemoval).toBeVisible();
    problems.push(...await contrastProblems(page, tr('deleteBankConnection')));
    await confirmRemoval.getByRole('button', { name: tr('bankConnectionDeleteButton') }).click();
    await expect(confirmRemoval.getByRole('button', { name: tr('bankConnectionDeleting') })).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('deleteBankConnection')} (busy)`));
    release();
    await expect(confirmRemoval.getByRole('alert')).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('deleteBankConnection')} (error)`));
    await page.keyboard.press('Escape');
    await expect(confirmRemoval).toHaveCount(0);
    await page.unroute('**/api/bank/backend?backend=bnp');

    // A synchronization in error, with its log open
    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    problems.push(...await contrastProblems(page, 'sync'));

    expect(problems).toEqual([]);
});
