// Single sign-on login through OpenID Connect (src/lib/Oidc.php, src/resources/Oidc.php):
// opt-in, nothing shown until the administrator configures it; account mapping by
// username (preferred_username, default) or by email (oidc_username_claim); the provider
// refusing the login or an unmapped identity both show a translated error on the login
// screen.
const { test, expect, tr } = require('../fixtures');
const { startFakeOidc } = require('../fake-oidc');

let fakeOidc;

test.beforeAll(async () => {
    fakeOidc = await startFakeOidc();
});

test.afterAll(async () => {
    await fakeOidc.close();
});

test.beforeEach(() => {
    fakeOidc.reset();
});

/**
 * Enables single sign-on against the fake provider (Administration > Settings, as
 * mfa.spec.js enforces the second factor)
 * @param {import('@playwright/test').APIRequestContext} api - As the administrator
 * @param {string} [usernameClaim] - preferred_username (default) or email
 */
async function enableOidc(api, usernameClaim) {
    const values = { oidc_issuer: fakeOidc.url, oidc_client_id: 'e2e-client', oidc_client_secret: 'e2e-secret' };
    if (usernameClaim) {
        values.oidc_username_claim = usernameClaim;
    }
    const response = await api.put('/api/system/config', { data: { values } });
    expect(response.ok(), await response.text()).toBeTruthy();
}

test('no SSO button while single sign-on is not configured', async ({ page }) => {
    await page.goto('/portal/');
    await expect(page.getByRole('link', { name: tr('oidcLoginButton') })).toHaveCount(0);
});

test('the administrator enables it, the SSO button logs in as the mapped user', async ({ page, api }) => {
    await enableOidc(api);
    await page.goto('/portal/');
    const button = page.getByRole('link', { name: tr('oidcLoginButton') });
    await expect(button).toBeVisible();

    fakeOidc.setUser({ preferred_username: 'admin' });
    await button.click();

    await expect(page.locator('.user-menu-toggle')).toBeVisible();
    expect(new URL(page.url()).search).toBe('');
});

test('mapping by email', async ({ page, api }) => {
    await enableOidc(api, 'email');
    await page.goto('/portal/');
    fakeOidc.setUser({ email: 'marie@example.com' });
    await page.getByRole('link', { name: tr('oidcLoginButton') }).click();

    await expect(page.locator('.user-menu-toggle')).toBeVisible();
    await page.locator('.user-menu-toggle').click();
    await expect(page.getByRole('menuitem', { name: tr('myProfile') })).toBeVisible();
});

test('an unknown identity shows the translated error', async ({ page, api }) => {
    await enableOidc(api);
    await page.goto('/portal/');
    fakeOidc.setUser({ preferred_username: 'ghost' });
    await page.getByRole('link', { name: tr('oidcLoginButton') }).click();

    await expect(page.getByRole('alert')).toContainText(tr('oidcErrorUnknownUser'));
    expect(new URL(page.url()).search).toBe('');
    await expect(page.getByLabel(tr('username'))).toBeVisible();
});

test('the provider refusing the login shows the translated error', async ({ page, api }) => {
    await enableOidc(api);
    await page.goto('/portal/');
    fakeOidc.deny();
    await page.getByRole('link', { name: tr('oidcLoginButton') }).click();

    await expect(page.getByRole('alert')).toContainText(tr('oidcErrorDenied'));
});
