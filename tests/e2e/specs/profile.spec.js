// Profile page, in three sub-tabs: personal information and language, notifications
// (channels, large expense threshold, budget watched and its threshold), devices
const { test, expect, tr } = require('../fixtures');
const { startFakeSmtp } = require('../fake-smtp');

/** Heading of each sub-tab of the profile page */
const HEADINGS = { profileTabProfile: 'myProfile', notificationsTitle: 'notificationsTitle', myDevices: 'myDevices' };

/**
 * Opens the profile page from the user menu, on one of its sub-tabs
 * @param {import('@playwright/test').Page} page
 * @param {string} [tab] - Label of the sub-tab: profileTabProfile (shown first), notificationsTitle or myDevices
 */
async function openProfile(page, tab = 'profileTabProfile') {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await expect(page.getByRole('heading', { name: tr('myProfile') })).toBeVisible();
    if (tab !== 'profileTabProfile') {
        await showProfileTab(page, tab);
    }
}

/**
 * Shows a sub-tab of the profile page
 * @param {import('@playwright/test').Page} page
 * @param {string} tab - Label of the sub-tab
 */
async function showProfileTab(page, tab) {
    await page.getByRole('tab', { name: tr(tab), exact: true }).click();
    await expect(page.getByRole('tab', { name: tr(tab), exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('heading', { name: tr(HEADINGS[tab]), exact: true })).toBeVisible();
}

test('splits the profile into profile, notifications and devices sub-tabs', async ({ userPage: page }) => {
    await openProfile(page);

    // The profile first, without the notification settings
    await expect(page.getByRole('tab', { name: tr('profileTabProfile'), exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByLabel(tr('firstname'))).toBeVisible();
    await expect(page.getByLabel(tr('alertThreshold'))).toHaveCount(0);
    await expect(page.getByRole('table', { name: tr('notificationsTitle') })).toHaveCount(0);

    await showProfileTab(page, 'notificationsTitle');
    await expect(page.getByRole('table', { name: tr('notificationsTitle') })).toBeVisible();
    await expect(page.getByLabel(tr('firstname'))).toHaveCount(0);

    await showProfileTab(page, 'myDevices');
    await expect(page.locator('.transactions-hint')).toHaveText(tr('noDevices'));

    // Opened again from the menu: back to the profile
    await openProfile(page);
    await expect(page.getByRole('tab', { name: tr('profileTabProfile'), exact: true })).toHaveAttribute('aria-selected', 'true');
});

test('modifies the personal information', async ({ userPage: page }) => {
    await openProfile(page);

    await expect(page.getByLabel(tr('username'))).toHaveValue('marie');
    await page.getByLabel(tr('firstname')).fill('Marion');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));

    // Saved on the server
    await page.reload();
    await openProfile(page);
    await expect(page.getByLabel(tr('firstname'))).toHaveValue('Marion');
});

test('changes the password', async ({ userPage: page, request }) => {
    await openProfile(page);

    await page.getByLabel(tr('password')).fill('new-marie-password');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));

    const login = await request.post('/api/user/login', { data: { username: 'marie', password: 'new-marie-password' } });
    expect(login.ok()).toBeTruthy();
});

test('switches the portal to English', async ({ userPage: page }) => {
    await openProfile(page);

    await page.getByLabel(tr('language')).selectOption('en');
    await page.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.tabs').getByRole('button', { name: tr('tabTrends', {}, 'en'), exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
});

test('shows, reveals then deletes a device', async ({ adminPage: page }) => {
    await openProfile(page, 'myDevices');

    const device = page.locator('.device-item').filter({ hasText: 'iPhone de Ada' });
    const token = 'ab12'.repeat(16);
    await expect(device.locator('.device-token')).toHaveText(`${token.slice(0, 6)}…${token.slice(-6)}`);
    await device.getByRole('button', { name: tr('showToken') }).click();
    await expect(device.locator('.device-token')).toHaveText(token);

    await device.getByRole('button', { name: tr('deleteDevice') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('deviceDeleted'));
    await expect(page.locator('.transactions-hint')).toHaveText(tr('noDevices'));
});

/**
 * Checkbox of a channel of a kind of notification
 * @param {import('@playwright/test').Page} page
 * @param {string} kind - sync, rule, expense or budget
 * @param {string} channel - push or email
 */
function notification(page, kind, channel) {
    const name = tr('notificationsToggle', { channel: tr(`notificationsChannel_${channel}`), kind: tr(`notificationsKind_${kind}`) });
    return page.getByRole('table', { name: tr('notificationsTitle') }).getByRole('checkbox', { name, exact: true });
}

test('chooses the channels of each notification', async ({ userPage: page, api }) => {
    await openProfile(page, 'notificationsTitle');

    // Default: pushed, not emailed; no SMTP server, the email channel tells why
    for (const kind of ['sync', 'rule', 'expense']) {
        await expect(notification(page, kind, 'push')).toBeChecked();
        await expect(notification(page, kind, 'email')).not.toBeChecked();
        await expect(notification(page, kind, 'email')).toBeDisabled();
    }
    await expect(page.locator('#notifications-email-unavailable')).toContainText(tr('notificationsEmailNoSmtp'));

    await notification(page, 'sync', 'push').uncheck();
    await expect(page.locator('.toast')).toHaveText(tr('notificationsSaved'));

    // An administrator configures the SMTP server: the email channel can be chosen
    const response = await api.put('/api/system/config', { data: { values: { smtp_host: 'smtp.example.com', smtp_from: 'carbure@example.com' } } });
    expect(response.ok()).toBeTruthy();
    await page.reload();
    await openProfile(page, 'notificationsTitle');
    await expect(page.locator('#notifications-email-unavailable')).toHaveCount(0);
    await notification(page, 'expense', 'email').check();
    await expect(page.locator('.toast')).toHaveText(tr('notificationsSaved'));

    // Saved on the server
    await page.reload();
    await openProfile(page, 'notificationsTitle');
    await expect(notification(page, 'sync', 'push')).not.toBeChecked();
    await expect(notification(page, 'expense', 'email')).toBeChecked();
    await expect(notification(page, 'expense', 'push')).toBeChecked();
    await expect(notification(page, 'rule', 'email')).not.toBeChecked();

    // Without an email address in the profile, the reason changes; a channel already on
    // can still be turned off
    await showProfileTab(page, 'profileTabProfile');
    await page.getByLabel(tr('email'), { exact: true }).fill('');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));
    await showProfileTab(page, 'notificationsTitle');
    await expect(page.locator('#notifications-email-unavailable')).toContainText(tr('notificationsEmailNoAddress'));
    await expect(notification(page, 'expense', 'email')).toBeEnabled();
    await expect(notification(page, 'rule', 'email')).toBeDisabled();
});

test('alerts by email when the spending of a budget reaches the threshold', async ({ userPage: page, api }) => {
    const smtp = await startFakeSmtp();
    try {
        // An administrator configures the SMTP server (the fake one)
        const response = await api.put('/api/system/config', { data: { values: { smtp_host: '127.0.0.1', smtp_port: String(smtp.port), smtp_from: 'carbure@example.com' } } });
        expect(response.ok()).toBeTruthy();
        await openProfile(page, 'notificationsTitle');

        // Opt-in: off on every channel, 100 % by default
        await expect(notification(page, 'budget', 'push')).not.toBeChecked();
        await expect(notification(page, 'budget', 'email')).not.toBeChecked();
        const threshold = page.getByLabel(tr('budgetThreshold'));
        await expect(threshold).toHaveValue('100');

        // All the budgets by default; the budgets of the Budget tab offered in its order,
        // a sub-category after its parent
        const watched = page.getByLabel(tr('budgetWatched'));
        await expect(watched.locator('option:checked')).toHaveText(tr('budgetWatchedAll'));
        await expect(watched.locator('option')).toHaveText([tr('budgetWatchedAll'), 'Logement', 'Alimentation', 'Alimentation › Supermarché', 'Alimentation › Restaurant']);
        await watched.selectOption({ label: 'Logement' });
        await expect(page.locator('.toast')).toHaveText(tr('notificationsSaved'));

        // A threshold out of range: refused, not sent
        await threshold.fill('30');
        await threshold.press('Enter');
        await expect(page.locator('#budget-threshold-error')).toHaveText(tr('budgetThresholdInvalid'));
        await expect(threshold).toHaveAttribute('aria-invalid', 'true');

        await threshold.fill('90');
        await threshold.press('Enter');
        await expect(page.locator('.toast')).toHaveText(tr('notificationsSaved'));
        await expect(page.locator('#budget-threshold-error')).toHaveCount(0);
        await notification(page, 'budget', 'email').check();
        await expect(page.locator('.toast')).toHaveText(tr('notificationsSaved'));

        // Saved on the server
        await page.reload();
        await openProfile(page, 'notificationsTitle');
        await expect(page.getByLabel(tr('budgetThreshold'))).toHaveValue('90');
        await expect(page.getByLabel(tr('budgetWatched')).locator('option:checked')).toHaveText('Logement');
        await expect(notification(page, 'budget', 'email')).toBeChecked();

        // An import of the current month: Logement (950 € of 1 000 €) reaches 90 %
        const today = new Date();
        const date = [String(Math.min(today.getDate(), 28)).padStart(2, '0'), String(today.getMonth() + 1).padStart(2, '0'), today.getFullYear()].join('/');
        const statement = label => Buffer.from(`Date;Libellé;Montant\n${date};${label};-10,00\n`).toString('base64');
        const imported = await api.post('/api/transaction/import', { data: { file: statement('CB SUPERMARCHE MONOPRIX'), filename: 'releve.csv' } });
        expect(imported.ok()).toBeTruthy();
        await expect.poll(() => smtp.messages.length).toBe(1);
        expect(smtp.messages[0].to).toEqual(['marie@example.com']);
        expect(smtp.messages[0].data).toContain('Subject: [Carbure] Budget presque atteint : Logement');

        // Once a month: another import alerts nothing more
        const again = await api.post('/api/transaction/import', { data: { file: statement('CB SUPERMARCHE FRANPRIX'), filename: 'releve.csv' } });
        expect(again.ok()).toBeTruthy();
        expect(smtp.messages).toHaveLength(1);
    } finally {
        await smtp.close();
    }
});

test('sets the large expense threshold under its label in the notifications', async ({ userPage: page, request }) => {
    await openProfile(page, 'notificationsTitle');

    // Off by default; refused when negative
    const amount = page.getByLabel(tr('alertThreshold'));
    await expect(amount).toHaveValue('');
    await expect(amount).toHaveAttribute('placeholder', tr('alertThresholdPlaceholder'));
    await amount.fill('-20');
    await amount.press('Enter');
    await expect(page.locator('#alert-threshold-error')).toHaveText(tr('alertThresholdInvalid'));
    await expect(amount).toHaveAttribute('aria-invalid', 'true');

    await amount.fill('200');
    await amount.press('Enter');
    await expect(page.locator('.toast')).toHaveText(tr('notificationsSaved'));
    await expect(page.locator('#alert-threshold-error')).toHaveCount(0);

    // Saved on the server, in the profile read by the iOS app too
    await page.reload();
    await openProfile(page, 'notificationsTitle');
    await expect(page.getByLabel(tr('alertThreshold'))).toHaveValue('200');
    const token = await page.evaluate(() => localStorage.getItem('authToken'));
    const me = await request.get('/api/user/me', { headers: { Authorization: `Bearer ${token}` } });
    expect(Number((await me.json()).alert_threshold)).toBe(200);

    // Emptied: off again
    await page.getByLabel(tr('alertThreshold')).fill('');
    await page.getByLabel(tr('alertThreshold')).press('Enter');
    await expect(page.locator('.toast')).toHaveText(tr('notificationsSaved'));
    await page.reload();
    await openProfile(page, 'notificationsTitle');
    await expect(page.getByLabel(tr('alertThreshold'))).toHaveValue('');
});
