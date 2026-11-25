// OpenID Connect identity provider simulated for oidc.spec.js (discovery, authorization,
// token endpoints), on the port where the server redirects for single sign-on
// (E2E_PORT + 20, see src/lib/Oidc.php / src/resources/Oidc.php). No real login screen:
// the authorization endpoint redirects straight back to the callback with a code, the
// claims of the authenticated identity chosen by the test through setUser(). The token
// endpoint answers an unsigned id_token (alg "none", not checked by src/lib/Oidc.php:
// OIDC Core §3.1.3.7) carrying the nonce it received at the authorization endpoint.
// Modeled on tests/fixtures/fake-oidc.php, the equivalent PHP fixture of the integration
// tests.
const http = require('http');
const { URL, URLSearchParams } = require('url');

/**
 * Base64url, without padding (same encoding as src/lib/Base64Url.php)
 * @param {string} text
 * @returns {string}
 */
function base64url(text) {
    return Buffer.from(text, 'utf8').toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * Base64url decode
 * @param {string} text
 * @returns {string}
 */
function fromBase64url(text) {
    return Buffer.from(text.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8');
}

/**
 * Starts the fake identity provider on 127.0.0.1, port E2E_PORT + 20
 * @returns {Promise<{url: string, setUser: Function, deny: Function, reset: Function, close: Function}>}
 */
function startFakeOidc() {
    let nextClaims = { preferred_username: 'admin' };
    let denyNext = false;
    let issuer = '';

    const server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://127.0.0.1');
        const answer = (status, json) => {
            response.writeHead(status, { 'Content-Type': 'application/json' });
            response.end(JSON.stringify(json));
        };
        const redirect = location => {
            response.writeHead(302, { Location: location });
            response.end();
        };

        if (url.pathname === '/.well-known/openid-configuration') {
            return answer(200, {
                issuer,
                authorization_endpoint: `${issuer}/authorize`,
                token_endpoint: `${issuer}/token`,
            });
        }
        if (url.pathname === '/authorize') {
            const state = url.searchParams.get('state') || '';
            const nonce = url.searchParams.get('nonce') || '';
            const redirectUri = url.searchParams.get('redirect_uri') || '';
            if (denyNext) {
                denyNext = false;
                return redirect(`${redirectUri}?error=access_denied&state=${encodeURIComponent(state)}`);
            }
            const code = base64url(JSON.stringify({ ...nextClaims, nonce }));
            return redirect(`${redirectUri}?code=${encodeURIComponent(code)}&state=${encodeURIComponent(state)}`);
        }
        if (url.pathname === '/token' && request.method === 'POST') {
            let body = '';
            request.on('data', chunk => { body += chunk; });
            request.on('end', () => {
                const form = new URLSearchParams(body);
                const authorization = (request.headers.authorization || '').replace(/^Basic /, '');
                const clientId = Buffer.from(authorization, 'base64').toString('utf8').split(':')[0] || '';
                const claims = JSON.parse(fromBase64url(form.get('code') || '') || '{}');
                const data = { iss: issuer, aud: clientId, exp: Math.floor(Date.now() / 1000) + 300, ...claims };
                const idToken = `${base64url('{"alg":"none"}')}.${base64url(JSON.stringify(data))}.`;
                return answer(200, { access_token: 'e2e-access-token', token_type: 'Bearer', id_token: idToken });
            });
            return;
        }
        return answer(404, { error: 'not_found' });
    });

    return new Promise(resolve => {
        server.listen(Number(process.env.E2E_PORT || 8091) + 20, '127.0.0.1', () => {
            issuer = `http://127.0.0.1:${server.address().port}`;
            resolve({
                url: issuer,
                /** @param {Object} claims - Claims of the identity the next /authorize answers (preferred_username or email) */
                setUser: claims => { nextClaims = claims; },
                /** The next /authorize redirects with error=access_denied, as a user refused at the provider */
                deny: () => { denyNext = true; },
                reset: () => { nextClaims = { preferred_username: 'admin' }; denyNext = false; },
                close: () => new Promise(done => server.close(() => done())),
            });
        });
    });
}

module.exports = { startFakeOidc };
