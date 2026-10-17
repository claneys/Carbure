// Accessibility: every text of the portal is readable (WCAG 2.2 AA contrast), the
// buttons at rest and on hover, on every tab and in the modals (see a11y.js)
const { test, expect, tr, openTab, trustWoobRepository } = require('../fixtures');
const { contrastProblems } = require('../a11y');
const { startFakeSmtp } = require('../fake-smtp');
const { startFakePushRelay } = require('../fake-push-relay');
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
    for (const tab of ['tabUsers', 'tabSync', 'tabAgents', 'tabLogs', 'tabSettings', 'tabBackup']) {
        await page.getByRole('tab', { name: tr(tab), exact: true }).click();
        await expect(page.getByRole('tab', { name: tr(tab), exact: true })).toHaveAttribute('aria-selected', 'true');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);
        problems.push(...await contrastProblems(page, tr(tab)));
    }

    expect(problems).toEqual([]);
});

test('the import of a backup is readable: its files, an invalid one, the confirmation', async ({ adminPage: page }) => {
    const problems = [];
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabBackup'), exact: true }).click();
    await expect(page.locator('#backup-file')).toBeAttached();

    // A whole export saved as JSON (its datasets listed) is accepted like a ZIP
    const rules = { format: 'carbure-backup', version: 1, datasets: { rules: [], budgets: [] } };
    await page.locator('#backup-file').setInputFiles({ name: 'backup.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(rules)) });
    await expect(page.locator('input[data-file]')).toHaveCount(2);
    problems.push(...await contrastProblems(page, tr('backupImportChoose')));

    await page.locator('.backup-import-button').click();
    await expect(page.getByRole('dialog', { name: tr('backupImportConfirmTitle') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('backupImportConfirmTitle')));
    await page.getByRole('dialog').getByRole('button', { name: tr('cancel') }).click();

    await page.getByRole('button', { name: tr('backupOtherFile') }).click();
    await page.locator('#backup-file').setInputFiles({ name: 'notes.json', mimeType: 'application/json', buffer: Buffer.from('not json') });
    await expect(page.locator('.backup-dataset.invalid')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('backupFileInvalid')));

    expect(problems).toEqual([]);
});

test('the notifications of the profile and the test email are readable', async ({ adminPage: page }) => {
    const problems = [];

    // Profile: its sub-tabs, the notifications without SMTP server (email disabled, its
    // reason) with the settings of the alerts under their label
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    problems.push(...await contrastProblems(page, tr('profileTabProfile')));
    await page.getByRole('tab', { name: tr('myDevices'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('myDevices') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('myDevices')));
    await page.getByRole('tab', { name: tr('notificationsTitle'), exact: true }).click();
    await expect(page.locator('#notifications-email-unavailable')).toBeVisible();
    await expect(page.locator('#notifications-push-unavailable')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('notificationsTitle')));
    // The large expense threshold refused: its error message
    await page.getByLabel(tr('alertThreshold')).fill('-1');
    await page.getByLabel(tr('alertThreshold')).press('Enter');
    await expect(page.locator('#alert-threshold-error')).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('alertThreshold')} (error)`));
    // The budget alert threshold refused: its error message
    await page.getByLabel(tr('budgetThreshold')).fill('20');
    await page.getByLabel(tr('budgetThreshold')).press('Enter');
    await expect(page.locator('#budget-threshold-error')).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('budgetThreshold')} (error)`));

    // Settings: the test email failed (no SMTP server), then sent
    const smtp = await startFakeSmtp();
    try {
        await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
        await page.getByRole('tab', { name: tr('tabSettings'), exact: true }).click();
        await page.getByRole('button', { name: tr('smtpTestButton') }).click();
        await expect(page.locator('.smtp-test-result[role="alert"]')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('smtpTestTitle')} (error)`));

        await page.locator('#config-smtp_host').fill('127.0.0.1');
        await page.locator('#config-smtp_port').fill(String(smtp.port));
        await page.locator('#config-smtp_from').fill('carbure@example.com');
        // A setting changed: the note of the test email
        await expect(page.locator('.smtp-test-note')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('smtpTestTitle')} (unsaved)`));
        await page.getByRole('button', { name: tr('save') }).click();
        await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
        await page.getByRole('button', { name: tr('smtpTestButton') }).click();
        await expect(page.locator('.smtp-test-ok')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('smtpTestTitle')} (sent)`));
    } finally {
        await smtp.close();
    }

    expect(problems).toEqual([]);
});

test('the registration on the push service and the test push are readable', async ({ adminPage: page }) => {
    const problems = [];
    const relay = await startFakePushRelay();
    try {
        await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
        await page.getByRole('tab', { name: tr('tabSettings'), exact: true }).click();
        // Not registered yet: the note, the disabled test button
        await expect(page.getByText(tr('pushUnavailableAdmin'))).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('pushRegisterTitle')} (no key)`));

        await page.locator('#config-push_url').fill(relay.url);
        await page.getByRole('button', { name: tr('save') }).click();
        await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
        await page.getByRole('button', { name: tr('pushRegisterButton') }).click();
        await expect(page.locator('.push-register .push-ok')).toBeVisible();
        await page.getByRole('button', { name: tr('pushTestButton') }).click();
        await expect(page.locator('.push-test .push-ok')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('pushTestTitle')} (sent)`));

        // The push service unreachable: the error of the registration
        await page.locator('#config-push_url').fill('http://127.0.0.1:9');
        await page.getByRole('button', { name: tr('save') }).click();
        await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
        await page.getByRole('button', { name: tr('pushRenewButton') }).click();
        await expect(page.locator('.push-register .push-result[role="alert"]')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('pushRegisterTitle')} (error)`));
    } finally {
        await relay.close();
    }

    expect(problems).toEqual([]);
});

test('the API tab is readable, its link on hover included', async ({ adminPage: page }) => {
    // A test of its own: Swagger UI, framed by the tab, is long to load
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabApi'), exact: true }).click();
    await expect(page.getByRole('tab', { name: tr('tabApi'), exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(page.frameLocator('iframe.api-docs-frame').locator('.opblock').first()).toBeVisible({ timeout: 15_000 });
    expect(await contrastProblems(page, tr('tabApi'))).toEqual([]);
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

test('the update of the bank modules offered after a failed synchronization is readable', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'), { timeout: 15_000 });

    const update = page.locator('.sync-woob-help').getByRole('button', { name: tr('woobUpdateButton') });
    // Log collapsed, then open: the help and its button at rest and on hover
    await expect(update).toBeVisible();
    const problems = [...await contrastProblems(page, 'sync help')];
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    await expect(page.locator('.sync-log')).toBeVisible();
    problems.push(...await contrastProblems(page, 'sync help (log open)'));

    // On keyboard focus: a ring of 2px at least, the text still readable (the log before
    // it is focusable too, to scroll it with the keyboard)
    await update.focus();
    await page.keyboard.press('Shift+Tab');
    await expect(page.getByRole('list', { name: tr('syncLogLabel') })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(update).toBeFocused();
    const outline = await update.evaluate(el => {
        const style = getComputedStyle(el);
        return { style: style.outlineStyle, width: parseFloat(style.outlineWidth) };
    });
    expect(outline.style).not.toBe('none');
    expect(outline.width).toBeGreaterThanOrEqual(2);
    problems.push(...await contrastProblems(page, 'sync help (focus)'));
    expect(problems).toEqual([]);

    // In forced colors: a visible border, like the other buttons
    await page.emulateMedia({ forcedColors: 'active' });
    const border = await update.evaluate(el => {
        const style = getComputedStyle(el);
        return { style: style.borderTopStyle, width: parseFloat(style.borderTopWidth) };
    });
    expect(border.style).not.toBe('none');
    expect(border.width).toBeGreaterThanOrEqual(1);
});
