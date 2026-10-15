// Banners of the administrators: database update and weak session secret.
// The e2e database is up to date: the state of the schema is simulated.
const { test, expect, tr } = require('../fixtures');
const { startFakeSmtp } = require('../fake-smtp');

/**
 * Simulates a database with a pending migration and the example session secret
 * @param {import('@playwright/test').Page} page
 */
async function outdated(page) {
    await page.route('**/api/system/schema', route => route.fulfill({
        json: { version: '2026-10-13_base', pending: ['2026-11-01_example'], weakJwtSecret: true },
    }));
    await page.route('**/api/system/migrate', route => route.fulfill({
        json: { version: '2026-11-01_example', applied: ['2026-11-01_example'] },
    }));
}

test('an administrator updates the database from the banner', async ({ page, adminPage }) => {
    await outdated(page);
    await page.reload();

    const banner = page.locator('.schema-banner').filter({ hasText: tr('schemaUpdateAvailable', { count: 1 }) });
    await expect(banner).toBeVisible();
    await expect(page.locator('.schema-banner').filter({ hasText: tr('weakJwtSecret') })).toBeVisible();

    await banner.getByRole('button', { name: tr('schemaUpdate') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('migrationDone', { version: '2026-11-01_example' }));
    await expect(banner).toHaveCount(0);
});

test('a user does not see the banners of the administrators', async ({ page, userPage }) => {
    await outdated(page);
    await page.reload();

    await expect(page.locator('.transaction-item').first()).toBeVisible();
    await expect(page.locator('.schema-banner')).toHaveCount(0);
});

test('the up-to-date e2e database shows no banner', async ({ adminPage: page }) => {
    await expect(page.locator('.transaction-item').first()).toBeVisible();
    await expect(page.locator('.schema-banner')).toHaveCount(0);
});

/**
 * Opens a tab of the Administration menu
 * @param {import('@playwright/test').Page} page
 * @param {string} tab - Translation key of the tab
 */
async function openAdministration(page, tab) {
    await page.getByRole('button', { name: tr('tabAdministration') }).click();
    await page.getByRole('tab', { name: tr(tab) }).click();
}

test('an administrator sees the configuration and changes the safe settings', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSettings');
    await expect(page.getByRole('heading', { name: tr('configTitle') })).toBeVisible();

    // Secrets masked, the woob command read-only
    await expect(page.locator('.tab-panel')).not.toContainText('e2e-tests-only');
    const woobPath = page.locator('.config-row').filter({ has: page.locator('code', { hasText: /^woob_path$/ }) });
    await expect(woobPath.locator('.config-readonly')).toHaveText('woob');
    await expect(woobPath.locator('input, select')).toHaveCount(0);

    await page.locator('#config-log_level').selectOption('info');
    await page.locator('#config-woob_transactions').fill('250');
    await expect(page.locator('.config-actions')).toContainText(tr('configChanges', { count: 2 }));
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
    await expect(page.locator('.config-actions')).toContainText(tr('configNoChange'));

    // Saved in the file
    await page.getByRole('tab', { name: tr('tabLogs') }).click();
    await page.getByRole('tab', { name: tr('tabSettings') }).click();
    await expect(page.locator('#config-log_level')).toHaveValue('info');
    await expect(page.locator('#config-woob_transactions')).toHaveValue('250');
});

test('an administrator gets the request that starts the synchronization', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSync');
    await expect(page.getByRole('heading', { name: tr('syncCommandTitle') })).toBeVisible();

    // No token in the e2e configuration: one is created (the fixture accepts the confirmation)
    await expect(page.getByRole('alert')).toHaveText(tr('syncTokenMissing'));
    await page.getByRole('button', { name: tr('syncTokenCreate') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('syncTokenRenewed'));

    const curl = page.locator('.copy-field code').first();
    await expect(curl).toContainText(/curl -sN -H "X-Sync-Token: [0-9a-f]{48}" "http:\/\/127\.0\.0\.1:\d+\/api\/bank\/sync"/);

    // Masked again, one account
    await page.getByRole('button', { name: tr('syncTokenHide') }).click();
    await expect(curl).toContainText('X-Sync-Token: ••••');
    await page.locator('#sync-account').selectOption('00012345678@bnp');
    await expect(curl).toContainText('/api/bank/sync?account=00012345678%40bnp');
});

test('an administrator configures the SMTP server and sends a test email', async ({ adminPage: page }) => {
    const smtp = await startFakeSmtp();
    try {
        await openAdministration(page, 'tabSettings');
        await page.locator('#config-smtp_host').fill('127.0.0.1');
        await page.locator('#config-smtp_port').fill(String(smtp.port));
        await page.locator('#config-smtp_from').fill('carbure@example.com');
        await page.locator('#config-smtp_password').fill('mail-secret');
        // The test uses the saved configuration
        await expect(page.locator('.smtp-test-note')).toHaveText(tr('smtpTestUnsaved'));
        await page.getByRole('button', { name: tr('save') }).click();
        await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
        await expect(page.locator('.smtp-test-note')).toHaveCount(0);

        // The password is never sent back: only replaced
        await expect(page.locator('#config-smtp_password')).toHaveValue('');
        await expect(page.locator('#config-smtp_password')).toHaveAttribute('placeholder', tr('configPasswordKeep'));
        await expect(page.locator('.tab-panel')).not.toContainText('mail-secret');

        // To the address of the administrator
        await page.getByRole('button', { name: tr('smtpTestButton') }).click();
        await expect(page.getByRole('status').filter({ hasText: tr('smtpTestSent', { to: 'admin@example.com' }) })).toBeVisible();
        expect(smtp.messages).toHaveLength(1);
        expect(smtp.messages[0].from).toBe('carbure@example.com');
        expect(smtp.messages[0].to).toEqual(['admin@example.com']);
        expect(smtp.messages[0].data).toContain('Subject: [Carbure] Email de test');

        // The reply of a server that refuses the email
        await page.getByLabel(tr('smtpTestTitle')).fill('refused@example.com');
        await page.getByRole('button', { name: tr('smtpTestButton') }).click();
        await expect(page.locator('.smtp-test-result[role="alert"]')).toContainText('550 5.1.1 Mailbox unavailable');
        expect(smtp.messages).toHaveLength(1);
    } finally {
        await smtp.close();
    }
});
