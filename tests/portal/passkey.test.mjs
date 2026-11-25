// Passkey module (WebAuthn): the hand-written base64url <-> ArrayBuffer conversions, the
// decoding of the ceremony options and the encoding of a credential, and the mixin
// methods of the login screen and the profile ("Passkeys" sub-tab)
import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    bufferToBase64url,
    base64urlToBuffer,
    decodeCeremonyOptions,
    encodeCredential,
    createPasskeyModule
} from '../../portal/modules/passkeyModule.js';

/**
 * Replaces the global navigator (a getter-only property of Node's own global object)
 * @param {Object} value - Fake navigator ({userAgent, credentials})
 */
function setNavigator(value) {
    Object.defineProperty(globalThis, 'navigator', { value, configurable: true, writable: true });
}

/** `this` of the portal for the passkey methods, with the given API service stub */
function context(api = {}, overrides = {}) {
    const module = createPasskeyModule(() => api);
    return {
        ...module.data(),
        t: (key, params = {}) => (Object.keys(params).length ? `${key}:${JSON.stringify(params)}` : key),
        apiErrorText: error => (error && error.message) || String(error),
        showToast: () => {},
        askConfirm: async ({ action }) => {
            if (action) {
                await action();
            }
            return true;
        },
        apiBaseUrl: 'https://carbure.example.com/api',
        loginError: null,
        openSession: async function (token, username) {
            this.openedWith = { token, username };
        },
        ...overrides,
        ...Object.fromEntries(Object.entries(module.methods).map(([name, fn]) => [name, fn])),
        ...Object.fromEntries(Object.entries(module.computed).map(([name, fn]) => [name, fn]))
    };
}

// --- bufferToBase64url / base64urlToBuffer ---

test('bufferToBase64url: matches Carbure\\Lib\\Base64Url::encode (RFC 4648 §5, no padding)', () => {
    // "any carnal pleasure." -> known base64 "YW55IGNhcm5hbCBwbGVhc3VyZS4=" (RFC 4648 test vector)
    const bytes = new TextEncoder().encode('any carnal pleasure.');
    assert.equal(bufferToBase64url(bytes.buffer), 'YW55IGNhcm5hbCBwbGVhc3VyZS4');

    // Bytes that need the url-safe substitution (+ -> -, / -> _) and padding stripped
    const binary = new Uint8Array([0xfb, 0xff, 0xbf]);
    assert.equal(bufferToBase64url(binary), '-_-_');
});

test('base64urlToBuffer: the inverse of bufferToBase64url, with or without padding', () => {
    const original = new Uint8Array([0, 1, 2, 253, 254, 255, 16, 32]);
    const encoded = bufferToBase64url(original);
    assert.deepEqual([...new Uint8Array(base64urlToBuffer(encoded))], [...original]);
    assert.deepEqual([...new Uint8Array(base64urlToBuffer('-_-_'))], [0xfb, 0xff, 0xbf]);
});

test('bufferToBase64url then base64urlToBuffer round-trips random bytes', () => {
    const original = new Uint8Array(Array.from({ length: 37 }, (_, i) => (i * 29) % 256));
    const roundTripped = new Uint8Array(base64urlToBuffer(bufferToBase64url(original)));
    assert.deepEqual([...roundTripped], [...original]);
});

// --- decodeCeremonyOptions ---

test('decodeCeremonyOptions: challenge, user.id, excludeCredentials and allowCredentials decoded to ArrayBuffer', () => {
    const challenge = bufferToBase64url(new Uint8Array([1, 2, 3]));
    const userId = bufferToBase64url(new Uint8Array([9, 9]));
    const credentialId = bufferToBase64url(new Uint8Array([7]));

    const registration = decodeCeremonyOptions({
        challenge,
        rp: { id: 'carbure.example.com', name: 'Carbure' },
        user: { id: userId, name: 'marie', displayName: 'marie' },
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
        excludeCredentials: [{ type: 'public-key', id: credentialId }]
    });
    assert.deepEqual([...new Uint8Array(registration.challenge)], [1, 2, 3]);
    assert.deepEqual([...new Uint8Array(registration.user.id)], [9, 9]);
    assert.equal(registration.user.name, 'marie');
    assert.deepEqual([...new Uint8Array(registration.excludeCredentials[0].id)], [7]);
    assert.equal(registration.rp.id, 'carbure.example.com');
    assert.deepEqual(registration.pubKeyCredParams, [{ type: 'public-key', alg: -7 }]);

    const login = decodeCeremonyOptions({
        challenge,
        rpId: 'carbure.example.com',
        timeout: 300000,
        userVerification: 'required',
        allowCredentials: [{ type: 'public-key', id: credentialId }]
    });
    assert.deepEqual([...new Uint8Array(login.challenge)], [1, 2, 3]);
    assert.deepEqual([...new Uint8Array(login.allowCredentials[0].id)], [7]);
    assert.equal(login.user, undefined);

    const bare = decodeCeremonyOptions({ challenge, allowCredentials: [] });
    assert.deepEqual(bare.allowCredentials, []);
});

// --- encodeCredential ---

test('encodeCredential: a registration (attestationObject) encoded in base64url', () => {
    const credential = {
        id: 'abc123',
        rawId: new Uint8Array([1, 2, 3]).buffer,
        type: 'public-key',
        response: {
            clientDataJSON: new Uint8Array([10, 11]).buffer,
            attestationObject: new Uint8Array([20, 21, 22]).buffer
        }
    };
    const encoded = encodeCredential(credential);
    assert.equal(encoded.id, 'abc123');
    assert.equal(encoded.type, 'public-key');
    assert.equal(encoded.rawId, bufferToBase64url(new Uint8Array([1, 2, 3])));
    assert.equal(encoded.response.clientDataJSON, bufferToBase64url(new Uint8Array([10, 11])));
    assert.equal(encoded.response.attestationObject, bufferToBase64url(new Uint8Array([20, 21, 22])));
    assert.equal(encoded.response.authenticatorData, undefined);
});

test('encodeCredential: an assertion (authenticatorData, signature, userHandle) encoded in base64url', () => {
    const credential = {
        id: 'abc123',
        rawId: new Uint8Array([1, 2, 3]).buffer,
        type: 'public-key',
        response: {
            clientDataJSON: new Uint8Array([10]).buffer,
            authenticatorData: new Uint8Array([30]).buffer,
            signature: new Uint8Array([40, 41]).buffer,
            userHandle: new Uint8Array([5, 6]).buffer
        }
    };
    const encoded = encodeCredential(credential);
    assert.equal(encoded.response.authenticatorData, bufferToBase64url(new Uint8Array([30])));
    assert.equal(encoded.response.signature, bufferToBase64url(new Uint8Array([40, 41])));
    assert.equal(encoded.response.userHandle, bufferToBase64url(new Uint8Array([5, 6])));
    assert.equal(encoded.response.attestationObject, undefined);
});

test('encodeCredential: a null userHandle (no resident key user handle sent by the authenticator) stays null', () => {
    const credential = {
        id: 'abc',
        rawId: new Uint8Array([1]).buffer,
        type: 'public-key',
        response: {
            clientDataJSON: new Uint8Array([1]).buffer,
            authenticatorData: new Uint8Array([2]).buffer,
            signature: new Uint8Array([3]).buffer,
            userHandle: null
        }
    };
    assert.equal(encodeCredential(credential).response.userHandle, null);
});

// --- passkeySupported ---

test('passkeySupported: needs window.PublicKeyCredential and a secure context', () => {
    const ctx = context();
    window.PublicKeyCredential = undefined;
    window.isSecureContext = true;
    assert.equal(ctx.passkeySupported(), false);

    window.PublicKeyCredential = function () {};
    window.isSecureContext = false;
    assert.equal(ctx.passkeySupported(), false);

    window.isSecureContext = true;
    assert.equal(ctx.passkeySupported(), true);
});

// --- Profile: loadPasskeys, addPasskey, deletePasskey ---

test('loadPasskeys: the list of the profile, the name prefilled from the browser once', async () => {
    setNavigator({ userAgent: 'Mozilla/5.0 (Windows NT 10.0) Firefox/120.0' });
    const api = { fetchPasskeys: async () => [{ id: 1, name: 'My phone', created_at: '2026-01-01', last_used_at: null, backed_up: false }] };
    const ctx = context(api);

    await ctx.loadPasskeys();
    assert.deepEqual(ctx.passkeys, [{ id: 1, name: 'My phone', created_at: '2026-01-01', last_used_at: null, backed_up: false }]);
    assert.ok(ctx.passkeyName);

    ctx.passkeyName = 'Chosen name';
    await ctx.loadPasskeys();
    assert.equal(ctx.passkeyName, 'Chosen name');
});

test('loadPasskeys: an error of the API empties the list without throwing', async () => {
    setNavigator({ userAgent: '' });
    const ctx = context({ fetchPasskeys: async () => { throw new Error('boom'); } });
    await ctx.loadPasskeys();
    assert.deepEqual(ctx.passkeys, []);
});

test('addPasskey: registration options, navigator.credentials.create(), then the API call; the list reloaded', async () => {
    const challenge = bufferToBase64url(new Uint8Array([1]));
    const userId = bufferToBase64url(new Uint8Array([2]));
    const options = {
        challenge_token: 'tok',
        publicKey: { challenge, rp: { id: 'carbure.example.com' }, user: { id: userId, name: 'marie', displayName: 'marie' }, excludeCredentials: [] }
    };
    const created = { id: 5, name: 'Firefox', created_at: '2026-01-01', last_used_at: null, backed_up: false };
    const calls = [];
    setNavigator({
        userAgent: 'Mozilla/5.0 (Windows NT 10.0) Firefox/120.0',
        credentials: {
            create: async ({ publicKey }) => {
                calls.push(publicKey);
                return {
                    id: 'cred', rawId: new Uint8Array([1]).buffer, type: 'public-key',
                    response: { clientDataJSON: new Uint8Array([1]).buffer, attestationObject: new Uint8Array([2]).buffer }
                };
            }
        }
    });
    const registerCalls = [];
    const api = {
        passkeyRegisterOptions: async () => options,
        registerPasskey: async (token, credential, name) => {
            registerCalls.push({ token, credential, name });
            return created;
        },
        fetchPasskeys: async () => [created]
    };
    const ctx = context(api);
    ctx.passkeyName = 'My Firefox';

    await ctx.addPasskey();

    assert.equal(calls.length, 1);
    assert.ok(calls[0].challenge instanceof ArrayBuffer);
    assert.equal(registerCalls.length, 1);
    assert.equal(registerCalls[0].token, 'tok');
    assert.equal(registerCalls[0].name, 'My Firefox');
    assert.equal(registerCalls[0].credential.response.attestationObject, bufferToBase64url(new Uint8Array([2])));
    assert.ok(ctx.passkeyName); // cleared then re-prefilled by the reload (loadPasskeys)
    assert.deepEqual(ctx.passkeys, [created]);
    assert.equal(ctx.passkeyError, null);
});

test('addPasskey: a cancelled native prompt (NotAllowedError) is quiet, no error shown', async () => {
    setNavigator({
        userAgent: '',
        credentials: { create: async () => { const e = new Error('cancelled'); e.name = 'NotAllowedError'; throw e; } }
    });
    const ctx = context({ passkeyRegisterOptions: async () => ({ challenge_token: 't', publicKey: { challenge: bufferToBase64url(new Uint8Array([1])) } }) });
    await ctx.addPasskey();
    assert.equal(ctx.passkeyError, null);
});

test('addPasskey: a refused registration (e.g. already registered) shows its translated error', async () => {
    setNavigator({
        userAgent: '',
        credentials: {
            create: async () => ({
                id: 'c', rawId: new Uint8Array([1]).buffer, type: 'public-key',
                response: { clientDataJSON: new Uint8Array([1]).buffer, attestationObject: new Uint8Array([1]).buffer }
            })
        }
    });
    const error = new Error('This passkey is already registered');
    error.code = 'passkey_already_registered';
    const ctx = context({
        passkeyRegisterOptions: async () => ({ challenge_token: 't', publicKey: { challenge: bufferToBase64url(new Uint8Array([1])) } }),
        registerPasskey: async () => { throw error; }
    });
    await ctx.addPasskey();
    assert.equal(ctx.passkeyError, 'This passkey is already registered');
});

test('deletePasskey: confirmed, calls the API and reloads the list', async () => {
    const deleteCalls = [];
    const ctx = context({
        deletePasskey: async id => deleteCalls.push(id),
        fetchPasskeys: async () => []
    });
    ctx.passkeys = [{ id: 7, name: 'Old phone' }];

    await ctx.deletePasskey({ id: 7, name: 'Old phone' });

    assert.deepEqual(deleteCalls, [7]);
    assert.deepEqual(ctx.passkeys, []);
});

// --- Login screen: loginWithPasskey ---

test('loginWithPasskey: options, navigator.credentials.get(), then the login opens the session', async () => {
    const challenge = bufferToBase64url(new Uint8Array([1, 2]));
    let getCalledWith = null;
    setNavigator({
        userAgent: '',
        credentials: {
            get: async ({ publicKey }) => {
                getCalledWith = publicKey;
                return {
                    id: 'cred', rawId: new Uint8Array([9]).buffer, type: 'public-key',
                    response: {
                        clientDataJSON: new Uint8Array([1]).buffer,
                        authenticatorData: new Uint8Array([2]).buffer,
                        signature: new Uint8Array([3]).buffer,
                        userHandle: new Uint8Array([4]).buffer
                    }
                };
            }
        }
    });
    const fetchCalls = [];
    globalThis.fetch = async (url, options) => {
        fetchCalls.push({ url, options });
        if (url.endsWith('/user/login/passkey/options')) {
            return { ok: true, json: async () => ({ challenge_token: 'chal-tok', publicKey: { challenge, allowCredentials: [] } }) };
        }
        return { ok: true, json: async () => ({ token: 'jwt-token', username: 'marie' }) };
    };

    const ctx = context();
    await ctx.loginWithPasskey();

    assert.equal(fetchCalls.length, 2);
    assert.equal(fetchCalls[0].url, 'https://carbure.example.com/api/user/login/passkey/options');
    assert.equal(fetchCalls[1].url, 'https://carbure.example.com/api/user/login/passkey');
    const body = JSON.parse(fetchCalls[1].options.body);
    assert.equal(body.challenge_token, 'chal-tok');
    assert.equal(body.credential.response.signature, bufferToBase64url(new Uint8Array([3])));
    assert.ok(getCalledWith.challenge instanceof ArrayBuffer);
    assert.deepEqual(ctx.openedWith, { token: 'jwt-token', username: 'marie' });
    assert.equal(ctx.loginError, null);
    assert.equal(ctx.passkeyLoggingIn, false);
});

test('loginWithPasskey: a cancelled native prompt is quiet, no login error shown', async () => {
    setNavigator({
        userAgent: '',
        credentials: { get: async () => { const e = new Error('cancelled'); e.name = 'NotAllowedError'; throw e; } }
    });
    globalThis.fetch = async () => ({
        ok: true,
        json: async () => ({ challenge_token: 't', publicKey: { challenge: bufferToBase64url(new Uint8Array([1])), allowCredentials: [] } })
    });
    const ctx = context();
    await ctx.loginWithPasskey();
    assert.equal(ctx.loginError, null);
});

test('loginWithPasskey: an unknown or invalid passkey (401) shows the translated API message', async () => {
    setNavigator({
        userAgent: '',
        credentials: {
            get: async () => ({
                id: 'c', rawId: new Uint8Array([1]).buffer, type: 'public-key',
                response: { clientDataJSON: new Uint8Array([1]).buffer, authenticatorData: new Uint8Array([1]).buffer, signature: new Uint8Array([1]).buffer, userHandle: null }
            })
        }
    });
    let call = 0;
    globalThis.fetch = async () => {
        call += 1;
        if (call === 1) {
            return { ok: true, json: async () => ({ challenge_token: 't', publicKey: { challenge: bufferToBase64url(new Uint8Array([1])), allowCredentials: [] } }) };
        }
        return { ok: false, status: 401 };
    };
    const ctx = context();
    await ctx.loginWithPasskey();
    assert.equal(ctx.loginError, 'passkeyLoginInvalid');
});

test('loginWithPasskey: a locked account (423) shows the account locked message', async () => {
    setNavigator({
        userAgent: '',
        credentials: {
            get: async () => ({
                id: 'c', rawId: new Uint8Array([1]).buffer, type: 'public-key',
                response: { clientDataJSON: new Uint8Array([1]).buffer, authenticatorData: new Uint8Array([1]).buffer, signature: new Uint8Array([1]).buffer, userHandle: null }
            })
        }
    });
    let call = 0;
    globalThis.fetch = async () => {
        call += 1;
        if (call === 1) {
            return { ok: true, json: async () => ({ challenge_token: 't', publicKey: { challenge: bufferToBase64url(new Uint8Array([1])), allowCredentials: [] } }) };
        }
        return { ok: false, status: 423 };
    };
    const ctx = context();
    await ctx.loginWithPasskey();
    assert.equal(ctx.loginError, 'accountLocked');
});

test('loginWithPasskey: the options request itself refused (409, migration not applied) shows a generic error', async () => {
    globalThis.fetch = async () => ({ ok: false, status: 409 });
    const ctx = context();
    await ctx.loginWithPasskey();
    assert.equal(ctx.loginError, 'loginError');
});

// --- passkeyLoginFailureKey / passkeyCeremonyError ---

test('passkeyLoginFailureKey: locked, too many logins, else an invalid/unknown passkey', () => {
    const ctx = context();
    assert.equal(ctx.passkeyLoginFailureKey(423), 'accountLocked');
    assert.equal(ctx.passkeyLoginFailureKey(429), 'tooManyLogins');
    assert.equal(ctx.passkeyLoginFailureKey(401), 'passkeyLoginInvalid');
});

test('passkeyCeremonyError: a cancelled native prompt is null, else the translated API error or the message', () => {
    const ctx = context();
    const cancelled = new Error('x');
    cancelled.name = 'NotAllowedError';
    assert.equal(ctx.passkeyCeremonyError(cancelled), null);

    const apiError = new Error('Invalid passkey');
    assert.equal(ctx.passkeyCeremonyError(apiError), 'Invalid passkey');
});
