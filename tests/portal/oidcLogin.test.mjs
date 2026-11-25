// Single sign-on login (portal/modules/oidcLoginModule.js): reading ?oidc=ok / ?oidc=error
// from the URL, exchanging the ticket cookie for the session, translated error messages
import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mockFetch } from './setup.mjs';
import { oidcResultOf, oidcErrorMessage, oidcClearedUrl, createOidcLoginModule } from '../../portal/modules/oidcLoginModule.js';

test('oidcResultOf: ok or error with its reason, null without ?oidc=', () => {
    assert.deepEqual(oidcResultOf('?oidc=ok'), { status: 'ok', reason: null });
    assert.deepEqual(oidcResultOf('?oidc=error&reason=locked'), { status: 'error', reason: 'locked' });
    assert.equal(oidcResultOf(''), null);
    assert.equal(oidcResultOf('?foo=bar'), null);
    assert.equal(oidcResultOf('?oidc=whatever'), null);
});

test('oidcErrorMessage: translated per reason, an unknown one falls back to the generic failure', () => {
    const t = key => key;
    assert.equal(oidcErrorMessage('denied', t), 'oidcErrorDenied');
    assert.equal(oidcErrorMessage('unknown_user', t), 'oidcErrorUnknownUser');
    assert.equal(oidcErrorMessage('locked', t), 'oidcErrorLocked');
    assert.equal(oidcErrorMessage('failed', t), 'oidcErrorFailed');
    assert.equal(oidcErrorMessage('something-else', t), 'oidcErrorFailed');
    assert.equal(oidcErrorMessage(null, t), 'oidcErrorFailed');
});

test('oidcClearedUrl: removes oidc and reason, keeps the rest', () => {
    assert.equal(oidcClearedUrl('https://carbure.example.com/portal/?oidc=ok&foo=bar'), 'https://carbure.example.com/portal/?foo=bar');
    assert.equal(oidcClearedUrl('https://carbure.example.com/portal/?oidc=error&reason=locked'), 'https://carbure.example.com/portal/');
});

/** `this` of the portal, with the oidc module mixed in */
function context() {
    const module = createOidcLoginModule(() => null);
    return {
        ...module.methods,
        apiBaseUrl: 'https://carbure.example.com/api',
        loginError: null,
        opened: null,
        t: key => key,
        apiErrorText: error => (error && error.code) || (error && error.message) || 'loginError',
        openSession(token, username) {
            this.opened = { token, username };
        }
    };
}

test('created(): without ?oidc= nothing happens', async () => {
    window.location = { search: '', href: 'https://carbure.example.com/portal/' };
    window.history = { replaceState: () => assert.fail('should not be called') };
    const ctx = context();
    await ctx.handleOidcRedirect();
    assert.equal(ctx.opened, null);
    assert.equal(ctx.loginError, null);
});

test('?oidc=ok exchanges the ticket cookie for the session, then clears the URL', async () => {
    window.location = { search: '?oidc=ok', href: 'https://carbure.example.com/portal/?oidc=ok' };
    let replaced = null;
    window.history = { replaceState: (state, title, url) => { replaced = url; } };
    const calls = mockFetch(() => new Response(JSON.stringify({ token: 'jwt', username: 'user' }), { status: 200 }));
    globalThis.fetch = window.fetch;

    const ctx = context();
    await ctx.handleOidcRedirect();

    assert.equal(calls[0].url, 'https://carbure.example.com/api/user/login/oidc');
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(calls[0].options.credentials, 'same-origin');
    assert.deepEqual(ctx.opened, { token: 'jwt', username: 'user' });
    assert.equal(replaced, 'https://carbure.example.com/portal/');
});

test('?oidc=ok with an expired ticket (401) shows the translated error', async () => {
    window.location = { search: '?oidc=ok', href: 'https://carbure.example.com/portal/?oidc=ok' };
    window.history = { replaceState: () => {} };
    mockFetch(() => new Response(JSON.stringify({ error: 'Expired', error_code: 'oidc_ticket_invalid' }), { status: 401 }));
    globalThis.fetch = window.fetch;

    const ctx = context();
    await ctx.handleOidcRedirect();

    assert.equal(ctx.opened, null);
    assert.equal(ctx.loginError, 'oidc_ticket_invalid');
});

test('?oidc=error&reason=... shows the translated message per reason, and clears the URL', async () => {
    for (const [reason, key] of [['denied', 'oidcErrorDenied'], ['unknown_user', 'oidcErrorUnknownUser'], ['locked', 'oidcErrorLocked'], ['failed', 'oidcErrorFailed']]) {
        window.location = { search: `?oidc=error&reason=${reason}`, href: `https://carbure.example.com/portal/?oidc=error&reason=${reason}` };
        let replaced = null;
        window.history = { replaceState: (state, title, url) => { replaced = url; } };
        const ctx = context();

        await ctx.handleOidcRedirect();

        assert.equal(ctx.loginError, key, reason);
        assert.equal(replaced, 'https://carbure.example.com/portal/');
    }
});

test('a network failure falls back to the generic login error', async () => {
    window.location = { search: '?oidc=ok', href: 'https://carbure.example.com/portal/?oidc=ok' };
    window.history = { replaceState: () => {} };
    window.fetch = async () => { throw new Error('network down'); };
    globalThis.fetch = window.fetch;

    const ctx = context();
    await ctx.handleOidcRedirect();

    assert.equal(ctx.loginError, 'loginError');
});
