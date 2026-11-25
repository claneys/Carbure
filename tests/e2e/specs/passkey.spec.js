// Native passkey (WebAuthn) login: added from the profile, used to log in without
// typing anything (a discoverable credential with user verification, standing for the
// second factor too), deleted, a further attempt then refused. Chromium's virtual
// authenticator (CDP WebAuthn domain) replaces a real security key or platform
// authenticator.
// PITFALL: an IP address is not a valid WebAuthn RP ID, so every page of this spec is
// opened on http://localhost:<port> (not 127.0.0.1, the baseURL of the other specs, see
// fixtures.js' passkeyLocalhostUrl/openPortalOnLocalhost): the server derives the RP ID
// from the request Host header (OAuth::baseUrl(), no public_url in conf/e2e.ini), and
// the PHP built-in server of playwright.config.js binds 127.0.0.1, which also answers on
// localhost.
const { test, expect, tr, passkeyLocalhostUrl, openPortalOnLocalhost, addVirtualAuthenticator } = require('../fixtures');

/**
 * Opens the "Passkeys" sub-tab of the profile
 * @param {import('@playwright/test').Page} page
 */
async function openPasskeysTab(page) {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await page.getByRole('tab', { name: tr('passkeyTabTitle'), exact: true }).click();
    await expect(page.getByRole('tab', { name: tr('passkeyTabTitle'), exact: true })).toHaveAttribute('aria-selected', 'true');
}

/**
 * Logs out through the user menu
 * @param {import('@playwright/test').Page} page
 */
async function logout(page) {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('logout') }).click();
}

test('adds a passkey from the profile, logs in with it, deletes it, a login attempt is then refused', async ({ page, request }) => {
    await addVirtualAuthenticator(page);
    await openPortalOnLocalhost(page, request, 'marie');

    // Added from the profile: an empty list, the name prefilled from the browser
    await openPasskeysTab(page);
    await expect(page.locator('.profile-passkeys')).toContainText(tr('passkeyEmpty'));
    await expect(page.getByLabel(tr('passkeyNameLabel'))).not.toHaveValue('');
    await page.getByLabel(tr('passkeyNameLabel')).fill('My security key');
    await page.getByRole('button', { name: tr('passkeyAddButton') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('passkeyAddedToast'));
    const item = page.locator('.profile-passkeys .device-item');
    await expect(item).toHaveCount(1);
    await expect(item).toContainText('My security key');
    await expect(item.locator('.device-meta')).toHaveCount(1); // created_at only, not used yet

    // Logged out, logged in again with the passkey button alone, nothing typed
    await logout(page);
    await expect(page.getByRole('button', { name: tr('loginButton'), exact: true })).toBeVisible();
    await page.getByRole('button', { name: tr('passkeyLoginButton') }).click();
    await expect(page.locator('.user-menu-name')).toHaveText('marie');

    // The passkey was used: last_used_at now shown next to it
    await openPasskeysTab(page);
    await expect(item.locator('.device-meta')).toHaveCount(2);

    // Deleted, with the existing confirmation dialog
    await page.getByRole('button', { name: new RegExp(tr('passkeyDeleteTitle')) }).click();
    const dialog = page.getByRole('dialog', { name: tr('passkeyDeleteTitle') });
    await expect(dialog).toContainText(tr('passkeyDeleteConfirm', { name: 'My security key' }));
    await dialog.getByRole('button', { name: tr('passkeyDeleteButton') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('passkeyDeletedToast'));
    await expect(item).toHaveCount(0);
    await expect(page.locator('.profile-passkeys')).toContainText(tr('passkeyEmpty'));

    // Logged out, a further login attempt with the (now deleted) passkey is refused
    await logout(page);
    await page.getByRole('button', { name: tr('passkeyLoginButton') }).click();
    await expect(page.locator('.login-error')).toContainText(tr('passkeyLoginInvalid'));
});

test('no passkey button on the login screen when this browser has no WebAuthn API', async ({ page }) => {
    await page.addInitScript(() => {
        delete window.PublicKeyCredential;
    });
    await page.goto(`${passkeyLocalhostUrl()}/portal/`);
    await expect(page.getByRole('button', { name: tr('loginButton'), exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: tr('passkeyLoginButton') })).toHaveCount(0);
});
