/**
 * OIDC Login Module
 *
 * Return of the single sign-on login (src/resources/Oidc.php): the provider sends the
 * browser back to the login screen with ?oidc=ok (the ticket cookie it just set is
 * exchanged for the session with a plain POST) or ?oidc=error&reason=<denied|
 * unknown_user|locked|failed> (a translated message, never a token exchange). Both
 * query parameters are removed from the address once read.
 *
 * @module oidcLoginModule
 */

import { responseError } from '../services/apiService.js';

/** Translation key of each reason given by the callback (closed list, src/resources/Oidc.php::reasonOf) */
const REASON_KEYS = {
    denied: 'oidcErrorDenied',
    unknown_user: 'oidcErrorUnknownUser',
    locked: 'oidcErrorLocked',
    failed: 'oidcErrorFailed'
};

/**
 * Result of the single sign-on carried by the URL of the page (?oidc=ok or
 * ?oidc=error&reason=...)
 * @param {string} search - location.search
 * @returns {{status: string, reason: string|null}|null} null if there is none
 */
export function oidcResultOf(search) {
    const params = new URLSearchParams(search);
    const status = params.get('oidc');
    if (status !== 'ok' && status !== 'error') {
        return null;
    }
    return { status, reason: params.get('reason') };
}

/**
 * Message of a failed single sign-on, translated; an unrecognized reason falls back to
 * the generic failure
 * @param {string|null} reason - denied, unknown_user, locked, failed (or anything else)
 * @param {Function} t - Translation function (key) => string
 * @returns {string}
 */
export function oidcErrorMessage(reason, t) {
    return t(REASON_KEYS[reason] || REASON_KEYS.failed);
}

/**
 * The address of the page without the oidc/reason query parameters
 * @param {string} href - location.href
 * @returns {string}
 */
export function oidcClearedUrl(href) {
    const url = new URL(href);
    url.searchParams.delete('oidc');
    url.searchParams.delete('reason');
    return url.toString();
}

/**
 * Creates the OIDC login module mixin
 * @param {Function} getApiService - Function that returns the API service instance (unused: the exchange needs the cookie, not a JWT)
 * @returns {Object} Vue mixin
 */
export function createOidcLoginModule(getApiService) {
    return {
        created() {
            this.handleOidcRedirect();
        },

        methods: {
            /**
             * Reads ?oidc=... once at load, removes it from the address, and either
             * exchanges the ticket cookie for the session or shows the translated error
             * @returns {Promise<void>}
             */
            async handleOidcRedirect() {
                const result = oidcResultOf(window.location.search);
                if (!result) {
                    return;
                }
                window.history.replaceState({}, '', oidcClearedUrl(window.location.href));

                if (result.status === 'error') {
                    this.loginError = oidcErrorMessage(result.reason, this.t);
                    return;
                }

                try {
                    const response = await fetch(`${this.apiBaseUrl}/user/login/oidc`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin'
                    });
                    const text = await response.text();
                    if (!response.ok) {
                        this.loginError = this.apiErrorText(responseError(response.status, text, this.t('loginError')));
                        return;
                    }
                    const data = JSON.parse(text);
                    await this.openSession(data.token, data.username);
                } catch (error) {
                    this.loginError = this.t('loginError');
                }
            }
        }
    };
}
