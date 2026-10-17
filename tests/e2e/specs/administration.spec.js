// Banners of the administrators: database update and weak session secret.
// The e2e database is up to date: the state of the schema is simulated.
const { test, expect, tr } = require('../fixtures');
const { startFakeSmtp } = require('../fake-smtp');
const { startFakePushRelay } = require('../fake-push-relay');

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

test('the scheduler passes the sync token in a header, the URL token is deprecated', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSync');
    await page.getByRole('button', { name: tr('syncTokenCreate') }).click();
    const curl = page.locator('.copy-field code').first();
    await expect(curl).toContainText(/X-Sync-Token: [0-9a-f]{48}/);
    // Only the header form is shown: never the token in the URL
    await expect(curl).not.toContainText('token=');
    const token = (await curl.textContent()).match(/X-Sync-Token: ([0-9a-f]{48})/)[1];

    // An account that is not followed: the access is checked, then 404 (no synchronization)
    const sync = '/api/bank/sync?account=unknown%40bank';

    // The header: no deprecation
    let response = await page.request.get(sync, { headers: { 'X-Sync-Token': token } });
    expect(response.status()).toBe(404);
    expect(response.headers()['deprecation']).toBeUndefined();

    // The query parameter still works, with the Deprecation and Link headers (RFC 9745)
    response = await page.request.get(`${sync}&token=${token}`);
    expect(response.status()).toBe(404);
    expect(response.headers()['deprecation']).toBe('@1792108800');
    expect(response.headers()['link']).toBe('<https://carbure.org/api/#bank-sync>; rel="deprecation"; type="text/html"');

    // A wrong token: refused, in the header or in the URL
    expect((await page.request.get(sync, { headers: { 'X-Sync-Token': 'wrong' } })).status()).toBe(401);
    expect((await page.request.get(`${sync}&token=wrong`)).status()).toBe(401);
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

test('an administrator registers the instance on the push service and sends a test push', async ({ adminPage: page }) => {
    const relay = await startFakePushRelay();
    try {
        await openAdministration(page, 'tabSettings');
        // No API key: the test push cannot be sent, the registration is offered
        await expect(page.getByText(tr('pushUnavailableAdmin'))).toBeVisible();
        await expect(page.getByRole('button', { name: tr('pushTestButton') })).toBeDisabled();

        // The fake push service instead of the official one
        await page.locator('#config-push_url').fill(relay.url);
        await page.getByRole('button', { name: tr('save') }).click();
        await expect(page.locator('.toast')).toHaveText(tr('configSaved'));

        // The consent first: what goes through the relay, the register button disabled
        // until it is accepted; cancelled, nothing is registered
        const register = page.getByRole('button', { name: tr('pushRegisterButton') });
        await register.click();
        let consent = page.getByRole('dialog', { name: tr('pushConsentTitle') });
        await expect(consent).toBeVisible();
        await expect(consent.locator('.push-consent-url')).toHaveText(relay.url);
        await expect(consent).toContainText(tr('pushConsentForwarded'));
        await expect(consent).toContainText(tr('pushConsentCustomRelay'));
        await expect(consent.getByRole('button', { name: tr('pushConsentSubmit') })).toBeDisabled();
        // The keyboard focus stays in the dialog
        await expect(consent.getByRole('heading', { name: tr('pushConsentTitle') })).toBeFocused();
        for (let i = 0; i < 5; i++) {
            await page.keyboard.press('Tab');
            expect(await consent.evaluate(dialog => dialog.contains(document.activeElement))).toBe(true);
        }
        await page.keyboard.press('Escape');
        await expect(consent).toHaveCount(0);
        await expect(register).toBeFocused();
        expect(relay.keys).toHaveLength(0);

        await register.click();
        consent = page.getByRole('dialog', { name: tr('pushConsentTitle') });
        await consent.getByLabel(tr('pushConsentAccept')).check();
        await consent.getByRole('button', { name: tr('pushConsentSubmit') }).click();
        await expect(consent).toHaveCount(0);
        await expect.poll(() => relay.keys.length).toBe(1);
        await expect(page.getByRole('status').filter({ hasText: tr('pushRegistered', { key: relay.keys[0].slice(4, 20) }) })).toBeVisible();
        // The key is saved, never shown
        await expect(page.locator('#config-push_api_key')).toHaveValue('');
        await expect(page.locator('#config-push_api_key')).toHaveAttribute('placeholder', tr('configPasswordKeep'));
        await expect(page.locator('.tab-panel')).not.toContainText(relay.keys[0]);
        // The consent recorded, for this relay
        await expect(page.locator('.push-consent-given')).toContainText(relay.url);

        // To the iOS device of the administrator (data set)
        await page.getByRole('button', { name: tr('pushTestButton') }).click();
        await expect(page.getByRole('status').filter({ hasText: tr('pushTestSent', { count: 1, devices: 1 }) })).toBeVisible();
        expect(relay.notifications).toHaveLength(1);
        expect(relay.notifications[0].title).toBe('Notification de test');
        expect(relay.notifications[0].key).toBe(relay.keys[0]);

        // Renewed after confirmation (accepted by the fixtures), the consent already given
        // for this relay: the new key is used
        await page.getByRole('button', { name: tr('pushRenewButton') }).click();
        await expect(page.getByRole('dialog', { name: tr('pushConsentTitle') })).toHaveCount(0);
        await expect.poll(() => relay.keys.length).toBe(2);
        await expect(page.getByRole('status').filter({ hasText: tr('pushRenewed', { key: relay.keys[1].slice(4, 20) }) })).toBeVisible();
        await page.getByRole('button', { name: tr('pushTestButton') }).click();
        await expect.poll(() => relay.notifications.length).toBe(2);
        expect(relay.notifications[1].key).toBe(relay.keys[1]);
    } finally {
        await relay.close();
    }
});

test('the push service unreachable: the registration tells why', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSettings');
    await page.locator('#config-push_url').fill('http://127.0.0.1:9');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
    await page.getByRole('button', { name: tr('pushRegisterButton') }).click();
    const consent = page.getByRole('dialog', { name: tr('pushConsentTitle') });
    await consent.getByLabel(tr('pushConsentAccept')).check();
    await consent.getByRole('button', { name: tr('pushConsentSubmit') }).click();
    await expect(page.locator('.push-register .push-result[role="alert"]')).toContainText('Push service unreachable');
});
