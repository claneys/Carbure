// Two-factor authentication of the portal (portal/modules/mfaModule.js): QR code drawn
// with the vendored library, secret and browser labels, second step of the login
import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mockFetch } from './setup.mjs';
import { qrPath, qrCodeOf, groupSecret, browserLabel, browserParts, createMfaModule } from '../../portal/modules/mfaModule.js';

const require = createRequire(import.meta.url);
const qrcode = require('../../portal/vendor/qrcode-generator/qrcode.js');

const URI = 'otpauth://totp/Carbure:marie?secret=JBSWY3DPEHPK3PXP&issuer=Carbure&algorithm=SHA1&digits=6&period=30';

test('qrPath: one square per dark module, with a quiet zone of 4 modules', () => {
    const qr = { getModuleCount: () => 2, isDark: (row, col) => row === col };

    assert.deepEqual(qrPath(qr), { size: 10, path: 'M4 4h1v1h-1zM5 5h1v1h-1z' });
});

test('qrCodeOf: the QR code of the otpauth URI, drawn by the vendored library', () => {
    window.qrcode = qrcode;
    try {
        const qr = qrcode(0, 'M');
        qr.addData(URI);
        qr.make();
        const drawn = qrCodeOf(URI);

        assert.equal(drawn.size, qr.getModuleCount() + 8);
        // The 3 finder patterns (7x7, 33 dark modules each) are there, at least
        assert.ok(drawn.path.split('z').length - 1 > 3 * 33);
        assert.ok(drawn.path.startsWith('M4 4h1v1h-1z'));
    } finally {
        delete window.qrcode;
    }
    // Library missing: no QR code (the secret is still shown in text)
    assert.equal(qrCodeOf(URI), null);
});

test('groupSecret: groups of 4 characters', () => {
    assert.equal(groupSecret('JBSWY3DPEHPK3PXP'), 'JBSW Y3DP EHPK 3PXP');
    assert.equal(groupSecret('ABCDE'), 'ABCD E');
    assert.equal(groupSecret(''), '');
});

test('browserLabel: browser and system of a User-Agent', () => {
    assert.equal(browserLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36'), 'Chrome · Windows');
    assert.equal(browserLabel('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) Gecko/20100101 Firefox/140.0'), 'Firefox · macOS');
    assert.equal(browserLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1'), 'Safari · iOS');
    assert.equal(browserLabel('Mozilla/5.0 (Windows NT 10.0) Chrome/140.0 Safari/537.36 Edg/140.0'), 'Edge · Windows');
    assert.equal(browserLabel('curl/8.0'), '');
    assert.equal(browserLabel(null), '');
});

test('browserParts and browserName: readable name of a trusted browser', () => {
    assert.deepEqual(browserParts('Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0'), { browser: 'Firefox', system: 'Linux' });
    assert.deepEqual(browserParts(''), { browser: '', system: '' });
    const { methods } = createMfaModule(() => null);
    const context = { t: (key, params) => key === 'browserOnSystem' ? `${params.browser} on ${params.system}` : key };
    const browserName = methods.browserName.bind(context);
    assert.equal(browserName('Mozilla/5.0 (Windows NT 10.0) Gecko/20100101 Firefox/140.0'), 'Firefox on Windows');
    assert.equal(browserName('SomeBot (Android)'), 'Android');
    assert.equal(browserName('Mozilla/5.0 Chrome/140.0'), 'Chrome');
    assert.equal(browserName('curl/8.0'), 'mfaUnknownBrowser');
});

/** `this` of the portal for the second step of the login */
function loginContext() {
    const module = createMfaModule(() => null);
    const context = {
        ...module.data(),
        ...module.methods,
        apiBaseUrl: 'https://carbure.example.com/api',
        loginForm: { username: 'marie', password: 'secret' },
        loginError: null,
        opened: null,
        t: key => key,
        $nextTick: () => {},
        openSession(token) {
            this.opened = token;
        },
    };
    context.startMfaLogin({ mfa: 'verify', mfa_token: 'challenge', exp: 0 });
    return context;
}

test('second step: the code and the trust of the browser are sent with the challenge', async () => {
    const calls = mockFetch(() => new Response(JSON.stringify({ token: 'jwt' }), { status: 200 }));
    globalThis.fetch = window.fetch;
    const context = loginContext();
    context.mfaCode = ' 123456 ';
    context.mfaTrust = true;

    await context.submitMfaLogin();

    assert.equal(calls[0].url, 'https://carbure.example.com/api/user/login/mfa');
    assert.deepEqual(JSON.parse(calls[0].options.body), { mfa_token: 'challenge', code: '123456', trust: true });
    assert.equal(calls[0].options.credentials, 'same-origin');
    assert.equal(context.opened, 'jwt');
    assert.equal(context.mfaLogin, null);
});

test('second step: the recovery codes of an app registered at login are shown before the session', async () => {
    mockFetch(() => new Response(JSON.stringify({ token: 'jwt', recovery_codes: ['abcde-fghij'] }), { status: 200 }));
    globalThis.fetch = window.fetch;
    const context = loginContext();
    context.mfaCode = '123456';

    await context.submitMfaLogin();
    assert.deepEqual(context.mfaLoginRecoveryCodes, ['abcde-fghij']);
    assert.equal(context.opened, null);

    await context.continueAfterRecoveryCodes();
    assert.equal(context.opened, 'jwt');
    assert.deepEqual(context.mfaLoginRecoveryCodes, []);
});

test('second step: a wrong code is asked again, a locked account or an expired login goes back to the password', async () => {
    for (const [status, error, back] of [[401, 'mfaInvalidCode', false], [423, 'accountLocked', true], [403, 'mfaExpired', true]]) {
        mockFetch(() => new Response(JSON.stringify({ error: 'x' }), { status }));
        globalThis.fetch = window.fetch;
        const context = loginContext();
        context.mfaCode = '000000';

        await context.submitMfaLogin();

        assert.equal(context.loginError, error);
        assert.equal(context.mfaLogin === null, back, String(status));
        assert.equal(context.mfaCode, '');
    }
});
