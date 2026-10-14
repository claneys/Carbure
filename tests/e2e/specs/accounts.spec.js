// Accounts tab (administrators): followed bank accounts and synchronization (fake woob)
const { test, expect, tr, openTab, escapeRegExp } = require('../fixtures');

/** Row of a followed account, by its bank id (account_number@bank) */
const account = (page, bankId) => page.locator('.account-item').filter({ has: page.locator('code', { hasText: new RegExp(`^${escapeRegExp(bankId)}$`) }) });

test('lists the followed accounts with their last synchronization', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));

    await expect(page.locator('.accounts-list .account-item')).toHaveCount(2);
    const bnp = account(page, '00012345678@bnp');
    await expect(bnp.locator('.account-name')).toContainText('BNP ···5678');
    await expect(bnp.locator('.account-name')).toContainText(tr('accountAddedBy', { user: 'admin' }));
    await expect(bnp.locator('.account-sync-state')).toHaveText(tr('lastSync_OK'));
    await expect(account(page, 'fail@bank').locator('.account-sync-state')).toHaveText(tr('lastSyncNever'));
});

test('synchronizes one account', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await account(page, '00012345678@bnp').getByRole('button', { name: tr('syncAccount') }).click();

    // Only this account: the other one (fail@bank) would end in error
    const status = page.locator('.sync-status');
    await expect(status).toContainText(tr('syncStatus_success'));
    await expect(page.locator('.sync-account')).toHaveCount(1);
    await expect(page.locator('.sync-account .step-ok')).not.toHaveCount(0);
    await expect(page.locator('.sync-step-count').first()).toContainText(tr('syncStepResult', { count: 1, created: 1 }));

    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    await expect(page.locator('.sync-log')).toContainText('Synchronization complete');
    // The system command run for each step, without a log file for woob
    const commands = page.locator('.sync-log li.sync-log-command');
    await expect(commands).toHaveCount(2);
    await expect(commands.first()).toContainText("Woob command 00012345678@bnp:");
    await expect(commands.first()).toContainText("bank coming '00012345678@bnp' --count");
    await expect(commands.first()).not.toContainText('--logging');

    // The new transactions are in the month
    await openTab(page, tr('tabTransactions'));
    // The card payment of the fake woob, besides the one of the data set
    await expect(page.locator('.transaction-item').filter({ hasText: 'BOULANGERIE' })).toHaveCount(2);
});

test('shows the debug logs of woob in the synchronization log', async ({ adminPage: page, api }) => {
    const saved = await api.put('/api/system/config', { data: { values: { woob_debug: true } } });
    expect(saved.ok(), await saved.text()).toBeTruthy();

    await openTab(page, tr('tabAccounts'));
    await account(page, 'fail@bank').getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));

    // The error output of woob, line by line, besides the steps
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    const debug = page.locator('.sync-log li.sync-log-debug');
    // Twice: the coming transactions fail, then the history
    await expect(debug.filter({ hasText: 'Woob debug fail@bank: DEBUG:woob.browser:Request: GET https://bank.example/accounts' })).toHaveCount(2);
    await expect(debug.filter({ hasText: "Woob debug fail@bank: AttributeError: 'NoneType' object has no attribute 'iter_accounts'" })).toHaveCount(2);

    // Nothing synchronized: no categorization, only the notification of the error
    await expect(page.locator('.sync-log')).not.toContainText('Updating missing categories');
    await expect(page.locator('.sync-log')).toContainText('Notifying users of fail@bank');
});

test('synchronizes every account at once', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    // An icon button: its name is in its label and its tooltip, no visible text
    const syncAll = page.getByRole('button', { name: tr('syncAllAccounts') });
    await expect(syncAll).toHaveAttribute('title', tr('syncAllAccounts'));
    await expect(syncAll).not.toContainText(tr('syncAllAccounts'));
    await syncAll.click();

    // Both followed accounts: fail@bank ends in error, the BNP account is synchronized
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await expect(page.locator('.sync-account')).toHaveCount(2);
    await expect(account(page, '00012345678@bnp').locator('.account-sync-state')).toHaveText(tr('lastSync_OK'));
    await expect(account(page, 'fail@bank').locator('.account-sync-state')).toHaveText(tr('lastSync_ERROR'));
    await expect(page.getByRole('button', { name: tr('syncAllAccounts') })).toBeEnabled();
});

test('reports a synchronization error', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await account(page, 'fail@bank').getByRole('button', { name: tr('syncAccount') }).click();

    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await expect(page.locator('.sync-step-error').first()).toContainText('iter_accounts');
    await expect(account(page, 'fail@bank').locator('.account-sync-state')).toHaveText(tr('lastSync_ERROR'));
});

test('adds accounts of a connected bank, from the accounts woob lists for it', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    // The banks connected in woob (one per login)
    await expect(page.locator('.bank-connection')).toHaveCount(2);
    await page.getByRole('button', { name: tr('addAccountsTo', { bank: 'BNP Paribas (bnp)' }) }).click();

    // woob bank list -b bnp: the account already followed is ticked and cannot be followed again
    const modal = page.getByRole('dialog', { name: tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' }) });
    const followed = modal.getByRole('checkbox', { name: /Compte chèques/ });
    await expect(followed).toBeChecked();
    await expect(followed).toBeDisabled();
    await expect(modal.locator('.account-item').filter({ hasText: 'Compte chèques' })).toContainText(tr('accountFollowed'));
    await expect(modal.getByRole('button', { name: tr('followSelected', { count: 0 }) })).toBeDisabled();

    await modal.getByRole('checkbox', { name: /Livret A/ }).check();
    await modal.getByRole('button', { name: tr('followSelected', { count: 1 }) }).click();

    // Named after its label in woob
    await expect(page.locator('.toast')).toHaveText(tr('accountsFollowedToast', { count: 1 }));
    await expect(modal).toHaveCount(0);
    await expect(account(page, '00087654321@bnp').locator('.account-name')).toContainText('Livret A');
});

test('names an account, shown instead of the bank and the end of its number', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    const bnp = account(page, '00012345678@bnp');
    await bnp.getByRole('button', { name: tr('editAccount') }).click();

    let modal = page.getByRole('dialog', { name: tr('editAccount') });
    // Only its name changes: the account comes from woob
    await expect(modal.locator('.account-woob-id')).toContainText('00012345678@bnp');
    await expect(modal.getByRole('textbox')).toHaveCount(1);
    await expect(modal.getByLabel(tr('accountLabel'))).toHaveValue('');
    await modal.getByLabel(tr('accountLabel')).fill('Compte joint');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountUpdated'));
    await expect(bnp.locator('.account-name')).toContainText('Compte joint');
    await expect(bnp.locator('.account-name')).not.toContainText('BNP ···5678');

    // Also in the synchronization progress
    await bnp.getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_success'));
    await expect(page.locator('.sync-account-name')).toContainText('Compte joint');

    // Emptied: back to the bank and the end of the number
    await bnp.getByRole('button', { name: tr('editAccount') }).click();
    modal = page.getByRole('dialog', { name: tr('editAccount') });
    await expect(modal.getByLabel(tr('accountLabel'))).toHaveValue('Compte joint');
    await modal.getByLabel(tr('accountLabel')).fill('');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountUpdated'));
    await expect(bnp.locator('.account-name')).toContainText('BNP ···5678');
});

test('keeps the history when the coming transactions fail', async ({ adminPage: page, api }) => {
    // Account of the fake woob whose coming transactions fail
    const created = await api.post('/api/bank', { data: { account_number: 'nocoming', bank_name: 'bank' } });
    expect(created.ok(), await created.text()).toBeTruthy();
    await openTab(page, tr('tabAccounts'));
    await account(page, 'nocoming@bank').getByRole('button', { name: tr('syncAccount') }).click();

    // The synchronization succeeds: only the coming step is in error
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_success'));
    const steps = page.locator('.sync-account .sync-step');
    await expect(steps.filter({ hasText: tr('syncStep_coming') })).toHaveClass(/step-error/);
    await expect(steps.filter({ hasText: tr('syncStep_coming') }).locator('.sync-step-error')).toContainText('coming transactions are not available');
    await expect(steps.filter({ hasText: tr('syncStep_history') })).toHaveClass(/step-ok/);
    await expect(account(page, 'nocoming@bank').locator('.account-sync-state')).toHaveText(tr('lastSync_OK'));

    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    await expect(page.locator('.sync-log')).toContainText('Coming failed nocoming@bank:');
    await expect(page.locator('.sync-log')).toContainText('Done nocoming@bank (history): 3 received');
});

test('approves the strong authentication asked by a bank', async ({ adminPage: page, api }) => {
    // Account of the fake woob that only answers in interactive mode (on a terminal)
    const created = await api.post('/api/bank', { data: { account_number: '2fa', bank_name: 'bank' } });
    expect(created.ok(), await created.text()).toBeTruthy();
    await openTab(page, tr('tabAccounts'));

    // A normal synchronization fails: woob needs the interactive mode
    await account(page, '2fa@bank').getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await expect(page.locator('.sync-step-error')).toContainText(tr('sync2faHelp'));

    // The account stays flagged (e.g. after a failed scheduled synchronization)
    const row = account(page, '2fa@bank');
    await expect(row.locator('.account-sync-state')).toHaveText(tr('lastSync2fa'));

    await row.getByRole('button', { name: tr('sync2faButton') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_success'));
    await expect(page.getByRole('button', { name: tr('sync2faButton') })).toHaveCount(0);
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    await expect(page.locator('.sync-log')).toContainText('Bank message 2fa@bank: Veuillez valider la connexion dans votre application bancaire.');
    await expect(account(page, '2fa@bank').locator('.account-sync-state')).toHaveText(tr('lastSync_OK'));
});

test('stops following an account', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await account(page, 'fail@bank').getByRole('button', { name: tr('deleteAccount') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountDeleted'));
    await expect(account(page, 'fail@bank')).toHaveCount(0);
    await expect(page.locator('.accounts-list .account-item')).toHaveCount(1);
});

/** Opens the modal connecting a bank and chooses its woob module */
async function connectModal(page, module) {
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('connectBankTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('connectBankTitle') });
    await modal.getByLabel(new RegExp(tr('connectBankModule'))).selectOption(module);
    return modal;
}

test('connects a new bank, then follows two of its accounts at once', async ({ adminPage: page }) => {
    // Boursorama: woob installs its module to describe its settings
    const modal = await connectModal(page, 'boursorama');
    const setup = modal.locator('fieldset.bank-setup');
    await expect(setup.getByLabel('Numéro client *')).toBeVisible();
    await expect(setup.getByLabel('Code secret *')).toHaveAttribute('type', 'password');
    // Its name is optional: the bank name is shown when none is given
    await expect(setup.getByLabel(tr('bankConnectionLabel'))).toHaveValue('');
    await setup.getByLabel('Numéro client *').fill('12345678');
    await setup.getByLabel('Code secret *').fill('123456');
    await modal.getByRole('button', { name: tr('bankConnect') }).click();

    // Straight to its accounts, listed by woob
    await expect(page.locator('.toast')).toHaveText(tr('bankConnected'));
    await expect(modal).toHaveCount(0);
    const add = page.getByRole('dialog', { name: tr('addAccountsTitle', { bank: 'Boursorama (boursorama)' }) });
    await expect(add.getByRole('checkbox')).toHaveCount(2);
    await add.getByRole('checkbox', { name: /Compte chèques/ }).check();
    await add.getByRole('checkbox', { name: /Livret A/ }).check();
    await add.getByRole('button', { name: tr('followSelected', { count: 2 }) }).click();

    await expect(page.locator('.toast')).toHaveText(tr('accountsFollowedToast', { count: 2 }));
    await expect(account(page, '00012345678@boursorama').locator('.account-name')).toContainText('Compte chèques');
    await expect(account(page, '00087654321@boursorama').locator('.account-name')).toContainText('Livret A');
    // The bank by its name, its woob name next to it (not repeated)
    const connection = page.locator('.bank-connection').filter({ hasText: 'Boursorama' });
    await expect(connection).toHaveCount(1);
    await expect(connection.locator('.account-name')).toHaveText('Boursorama');
    await expect(connection.locator('code')).toHaveText('boursorama');
});

test('connects a second login of a bank already connected', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('connectBankTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('connectBankTitle') });

    // BNP is connected (backend bnp): it stays offered for another login, given a readable
    // name (its woob name, bnp_2, is generated)
    const bank = modal.getByLabel(new RegExp(tr('connectBankModule')));
    await expect(bank.locator('option[value="bnp"]')).toContainText(tr('bankAnotherLogin'));
    await bank.selectOption('bnp');
    const setup = modal.locator('fieldset.bank-setup');
    await setup.getByLabel('Numéro client *').fill('87654321');
    await setup.getByLabel('Code secret *').fill('123456');
    await setup.getByLabel(tr('bankConnectionLabel')).fill('Compte joint');
    await modal.getByRole('button', { name: tr('bankConnect') }).click();

    // The accounts of this login, under its own name
    const add = page.getByRole('dialog', { name: tr('addAccountsTitle', { bank: 'Compte joint (bnp_2)' }) });
    await add.getByRole('checkbox', { name: /Livret A/ }).check();
    await add.getByRole('button', { name: tr('followSelected', { count: 1 }) }).click();

    await expect(page.locator('.toast')).toHaveText(tr('accountsFollowedToast', { count: 1 }));
    await expect(account(page, '00087654321@bnp_2')).toBeVisible();
    await expect(account(page, '00012345678@bnp')).toBeVisible();
});

test('renames a connected bank', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    const connection = page.locator('.bank-connection').filter({ has: page.locator('code', { hasText: /^bnp$/ }) });
    await expect(connection.locator('.account-name')).toHaveText('BNP Paribas');

    await connection.getByRole('button', { name: tr('renameBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) }).click();
    const modal = page.getByRole('dialog', { name: tr('renameBankConnection') });
    await expect(modal.locator('code')).toHaveText('bnp');
    await modal.getByLabel(tr('bankConnectionLabel')).fill('Comptes perso');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('bankConnectionRenamed'));
    await expect(modal).toHaveCount(0);
    await expect(connection.locator('.account-name')).toHaveText('Comptes perso');
    await expect(connection.getByRole('button', { name: tr('addAccountsTo', { bank: 'Comptes perso (bnp)' }) })).toBeVisible();

    // Kept by the server; an empty name shows the bank name again
    await page.reload();
    await openTab(page, tr('tabAccounts'));
    await expect(connection.locator('.account-name')).toHaveText('Comptes perso');
    await connection.getByRole('button', { name: tr('renameBankConnectionOf', { bank: 'Comptes perso (bnp)' }) }).click();
    await modal.getByLabel(tr('bankConnectionLabel')).fill('');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(connection.locator('.account-name')).toHaveText('BNP Paribas');
});

test('removes a connected bank, with its followed accounts', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await expect(page.locator('.bank-connection')).toHaveCount(2);
    await expect(account(page, '00012345678@bnp')).toBeVisible();
    const remove = page.getByRole('button', { name: tr('deleteBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) });
    const dialog = page.getByRole('dialog', { name: tr('deleteBankConnection') });

    // Asked in a modal of the portal, the focus on "Cancel": cancelled, nothing removed
    await remove.click();
    await expect(dialog).toContainText(tr('confirmDeleteBankConnection', { bank: 'BNP Paribas (bnp)', count: 1 }));
    await expect(dialog.getByRole('button', { name: tr('cancel') })).toBeFocused();
    await dialog.getByRole('button', { name: tr('cancel') }).click();
    await expect(dialog).toHaveCount(0);
    await expect(remove).toBeFocused();
    await expect(page.locator('.bank-connection')).toHaveCount(2);

    // Confirmed: the modal stays open with a loader until woob has removed the bank
    // (the request is held to see it), Escape does not close it meanwhile
    let release;
    const held = new Promise(resolve => { release = resolve; });
    await page.route('**/api/bank/backend?backend=bnp', async route => {
        await held;
        await route.continue();
    });
    await remove.click();
    await dialog.getByRole('button', { name: tr('bankConnectionDeleteButton') }).click();
    const busy = dialog.getByRole('button', { name: tr('bankConnectionDeleting') });
    await expect(busy).toBeVisible();
    await expect(busy).toHaveAttribute('aria-disabled', 'true');
    await expect(busy.locator('.material-icons.spin')).toBeVisible();
    await expect(dialog.getByRole('button', { name: tr('cancel') })).toBeDisabled();
    await expect(page.locator('.modal-confirm')).toHaveAttribute('aria-busy', 'true');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeVisible();
    release();

    await expect(dialog).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('bankConnectionDeleted'));
    await expect(page.locator('.bank-connection')).toHaveCount(1);
    await expect(page.locator('.bank-connection code')).toHaveText('cic_pro');
    // Its followed account is no longer followed, the other one stays
    await expect(account(page, '00012345678@bnp')).toHaveCount(0);
    await expect(account(page, 'fail@bank')).toBeVisible();

    // Kept by the server
    await page.reload();
    await openTab(page, tr('tabAccounts'));
    await expect(page.locator('.bank-connection')).toHaveCount(1);
});

test('a failed removal of a bank is shown in its confirmation modal', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.route('**/api/bank/backend?backend=bnp', route => route.fulfill({
        status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'woob could not remove the bank bnp', code: 500 }),
    }));
    await page.getByRole('button', { name: tr('deleteBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) }).click();
    const dialog = page.getByRole('dialog', { name: tr('deleteBankConnection') });
    await dialog.getByRole('button', { name: tr('bankConnectionDeleteButton') }).click();

    // The error in the modal, which can be confirmed again or cancelled
    await expect(dialog.getByRole('alert')).toHaveText('woob could not remove the bank bnp');
    await expect(dialog.getByRole('button', { name: tr('bankConnectionDeleteButton') })).not.toHaveAttribute('aria-disabled', 'true');
    await expect(dialog.getByRole('button', { name: tr('cancel') })).toBeEnabled();
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('.bank-connection')).toHaveCount(2);
});

test('a bank refusing the credentials can be connected again', async ({ adminPage: page }) => {
    const modal = await connectModal(page, 'cic');
    const setup = modal.locator('fieldset.bank-setup');
    await setup.getByLabel('Numéro client *').fill('wrong');
    await setup.getByLabel('Code secret *').fill('123456');
    await modal.getByRole('button', { name: tr('bankConnect') }).click();

    // The error of the bank, the modal kept to correct the credentials
    await expect(setup.getByRole('alert')).toContainText('Identifiant ou mot de passe incorrect');
    await setup.getByLabel('Numéro client *').fill('12345678');
    await modal.getByRole('button', { name: tr('bankConnect') }).click();
    await expect(page.getByRole('dialog', { name: tr('addAccountsTitle', { bank: 'CIC (cic)' }) })).toBeVisible();
});
