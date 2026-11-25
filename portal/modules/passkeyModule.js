/**
 * Passkey Module (WebAuthn)
 *
 * Native, passwordless login with a discoverable credential (resident key) and user
 * verification required, so logging in with it also stands for the second factor
 * (the TOTP step of mfaModule is never asked with a passkey). No runtime dependency:
 * the base64url <-> ArrayBuffer conversions and the navigator.credentials ceremonies
 * are hand-written here (no parseCreationOptionsFromJSON/toJSON).
 * - Login screen: a button shown when this browser supports WebAuthn in a secure
 *   context and the instance offers it (loginAlternatives of app.js).
 * - Profile ("Passkeys" sub-tab, profileModule.js): list, add (name prefilled from the
 *   browser, mfaModule's browserLabel), delete (confirmModule).
 *
 * @module passkeyModule
 */

import { browserLabel } from './mfaModule.js';

/**
 * Bytes of an ArrayBuffer (or a typed array view of one) as base64url, without padding
 * @param {ArrayBuffer|Uint8Array} buffer - Bytes
 * @returns {string} The base64url text
 */
export function bufferToBase64url(buffer) {
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let binary = '';
    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * A base64url text (with or without padding) decoded as an ArrayBuffer
 * @param {string} text - The base64url text
 * @returns {ArrayBuffer} The bytes
 */
export function base64urlToBuffer(text) {
    const padded = text.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(text.length / 4) * 4, '=');
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}

/**
 * The publicKey options of POST /user/passkey/options or /user/login/passkey/options,
 * ready for navigator.credentials.create()/get(): its binary fields (challenge,
 * user.id, excludeCredentials/allowCredentials ids) decoded from base64url to ArrayBuffer
 * @param {Object} publicKey - The publicKey options given by the API
 * @returns {Object} The same options, with its binary fields decoded
 */
export function decodeCeremonyOptions(publicKey) {
    const decoded = { ...publicKey, challenge: base64urlToBuffer(publicKey.challenge) };
    if (publicKey.user) {
        decoded.user = { ...publicKey.user, id: base64urlToBuffer(publicKey.user.id) };
    }
    for (const key of ['excludeCredentials', 'allowCredentials']) {
        if (Array.isArray(publicKey[key])) {
            decoded[key] = publicKey[key].map(cred => ({ ...cred, id: base64urlToBuffer(cred.id) }));
        }
    }
    return decoded;
}

/**
 * A PublicKeyCredential given by navigator.credentials.create() (registration) or
 * .get() (login), encoded for the API: id, rawId, type and the binary fields of its
 * response, all in base64url
 * @param {PublicKeyCredential} credential - The credential
 * @returns {{id: string, rawId: string, type: string, response: Object}}
 */
export function encodeCredential(credential) {
    const response = credential.response;
    const encoded = { clientDataJSON: bufferToBase64url(response.clientDataJSON) };
    // Registration (create()): attestationObject; assertion (get()): authenticatorData,
    // signature, userHandle (null without a resident key's user handle)
    if (response.attestationObject) {
        encoded.attestationObject = bufferToBase64url(response.attestationObject);
    } else {
        encoded.authenticatorData = bufferToBase64url(response.authenticatorData);
        encoded.signature = bufferToBase64url(response.signature);
        encoded.userHandle = response.userHandle ? bufferToBase64url(response.userHandle) : null;
    }
    return {
        id: credential.id,
        rawId: bufferToBase64url(credential.rawId),
        type: credential.type,
        response: encoded
    };
}

/**
 * Creates the passkey module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the passkey login and profile methods
 */
export function createPasskeyModule(getApiService) {
    return {
        data() {
            return {
                // Profile: list (GET /user/passkey, null until loaded), name typed to add one
                passkeys: null,
                passkeyName: '',
                passkeyBusy: false,
                passkeyError: null,
                // Login screen
                passkeyLoggingIn: false
            };
        },

        computed: {
            /**
             * Whether this browser can use a passkey: the WebAuthn API, in a secure
             * context (HTTPS, or localhost)
             * @returns {boolean}
             */
            passkeySupported() {
                return typeof window !== 'undefined' && !!window.PublicKeyCredential && !!window.isSecureContext;
            }
        },

        methods: {
            browserLabel,

            /**
             * Loads the passkeys of the profile
             * @returns {Promise<void>}
             */
            async loadPasskeys() {
                this.passkeyError = null;
                try {
                    this.passkeys = await getApiService().fetchPasskeys();
                } catch (error) {
                    this.passkeys = [];
                }
                if (!this.passkeyName.trim()) {
                    this.passkeyName = browserLabel(navigator.userAgent) || this.t('passkeyDefaultName');
                }
            },

            /**
             * Adds a passkey from the profile: registration options, the native
             * navigator.credentials.create() prompt, then the registration itself
             * @returns {Promise<void>}
             */
            async addPasskey() {
                this.passkeyBusy = true;
                this.passkeyError = null;
                try {
                    const options = await getApiService().passkeyRegisterOptions();
                    const credential = await navigator.credentials.create({ publicKey: decodeCeremonyOptions(options.publicKey || options) });
                    if (!credential) {
                        return;
                    }
                    const name = this.passkeyName.trim() || browserLabel(navigator.userAgent) || this.t('passkeyDefaultName');
                    await getApiService().registerPasskey(options.challenge_token, encodeCredential(credential), name);
                    this.passkeyName = '';
                    this.showToast(this.t('passkeyAddedToast'));
                    await this.loadPasskeys();
                } catch (error) {
                    this.passkeyError = this.passkeyCeremonyError(error);
                } finally {
                    this.passkeyBusy = false;
                }
            },

            /**
             * Deletes one of the passkeys of the profile, confirmed first
             * @param {Object} passkey - {id, name}
             * @returns {Promise<void>}
             */
            async deletePasskey(passkey) {
                const deleted = await this.askConfirm({
                    title: this.t('passkeyDeleteTitle'),
                    message: this.t('passkeyDeleteConfirm', { name: passkey.name }),
                    confirmLabel: this.t('passkeyDeleteButton'),
                    icon: 'delete',
                    danger: true,
                    action: () => getApiService().deletePasskey(passkey.id)
                });
                if (deleted) {
                    this.showToast(this.t('passkeyDeletedToast'));
                    await this.loadPasskeys();
                }
            },

            /**
             * Logs in with a discoverable passkey, without typing anything: login
             * options (public route, raw fetch like the password step), the native
             * navigator.credentials.get() prompt, then POST /user/login/passkey opens
             * the session (User::openSession, never asking the TOTP second factor)
             * @returns {Promise<void>}
             */
            async loginWithPasskey() {
                this.passkeyLoggingIn = true;
                this.loginError = null;
                try {
                    const optionsResponse = await fetch(`${this.apiBaseUrl}/user/login/passkey/options`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({})
                    });
                    if (!optionsResponse.ok) {
                        this.loginError = this.t('loginError');
                        return;
                    }
                    const options = await optionsResponse.json();
                    const credential = await navigator.credentials.get({ publicKey: decodeCeremonyOptions(options.publicKey) });
                    if (!credential) {
                        return;
                    }
                    const response = await fetch(`${this.apiBaseUrl}/user/login/passkey`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ challenge_token: options.challenge_token, credential: encodeCredential(credential) })
                    });
                    if (!response.ok) {
                        this.loginError = this.t(this.passkeyLoginFailureKey(response.status));
                        return;
                    }
                    const data = await response.json();
                    await this.openSession(data.token, data.username);
                } catch (error) {
                    // The user cancelled the native prompt (no passkey chosen, or timeout): quiet
                    if (!error || error.name !== 'NotAllowedError') {
                        this.loginError = this.t('loginError');
                    }
                } finally {
                    this.passkeyLoggingIn = false;
                }
            },

            /**
             * Translation key of a passkey login refused by the API
             * @param {number} status - HTTP status of POST /user/login/passkey
             * @returns {string} A key of portal/i18n
             */
            passkeyLoginFailureKey(status) {
                if (status === 423) {
                    return 'accountLocked';
                }
                return status === 429 ? 'tooManyLogins' : 'passkeyLoginInvalid';
            },

            /**
             * Message of a failed registration or assertion ceremony: a cancelled
             * native prompt is quiet (null, e.g. the user closed the dialog), the rest
             * through apiErrorText()
             * @param {Error} error - The error (DOMException of the WebAuthn API, or an API error)
             * @returns {string|null}
             */
            passkeyCeremonyError(error) {
                if (error && error.name === 'NotAllowedError') {
                    return null;
                }
                return this.apiErrorText(error) || String((error && error.message) || error);
            }
        }
    };
}
