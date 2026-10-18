// Push service (Carbure Push) simulated for the specs that register the instance and
// send a test push (Settings tab), on the port where the server sends its push
// notifications (E2E_PUSH_PORT, see playwright.config.js): proof of work of difficulty
// 4, every key accepted
// except those it revoked, the notifications kept in memory. As
// tests/fixtures/fake-push-relay.php does: the token 64 x "f" is unregistered.
const http = require('http');
const crypto = require('crypto');

/**
 * A new API key
 * @returns {{api_key: string, key_id: string, limits: Object}}
 */
function newKey() {
    const id = crypto.randomBytes(8).toString('hex');
    return { api_key: `cpk_${id}_${crypto.randomBytes(32).toString('hex')}`, key_id: id, limits: { notifications_per_day: 500, devices: 20 } };
}

/**
 * Starts the fake push service on 127.0.0.1, port E2E_PUSH_PORT
 * @returns {Promise<{url: string, keys: string[], notifications: Array<Object>, close: Function}>}
 */
function startFakePushRelay() {
    const keys = [];
    const revoked = new Set();
    const notifications = [];

    const server = http.createServer((request, response) => {
        let body = '';
        request.on('data', chunk => { body += chunk; });
        request.on('end', () => {
            const data = body ? JSON.parse(body) : {};
            const key = (request.headers.authorization || '').replace(/^Bearer /, '');
            const answer = (status, json) => {
                response.writeHead(status, { 'Content-Type': 'application/json' });
                response.end(JSON.stringify(json));
            };
            const known = keys.includes(key) && !revoked.has(key);

            if (request.url === '/v1/health') {
                return answer(200, { status: 'ok', version: 'e2e' });
            }
            if (request.url === '/v1/register/challenge') {
                return answer(200, { challenge: `e2e.${crypto.randomBytes(4).toString('hex')}`, difficulty: 4, algorithm: 'sha256', expires_at: 0 });
            }
            if (request.url === '/v1/register') {
                const hash = crypto.createHash('sha256').update(`${data.challenge}:${data.nonce}`).digest();
                if (hash[0] >> 4 !== 0) {
                    return answer(400, { error: 'The nonce does not solve the challenge', code: 400 });
                }
                const created = newKey();
                keys.push(created.api_key);
                return answer(201, created);
            }
            if (!known) {
                return answer(401, { error: 'Missing or invalid API key', code: 401 });
            }
            if (request.url === '/v1/keys/current') {
                return answer(200, { key_id: key.slice(4, 20), name: 'carbure.e2e', created_at: 0,
                    limits: { notifications_per_day: 500, devices: 20 }, devices: 1, used_today: notifications.filter(n => n.key === key).length });
            }
            if (request.url === '/v1/keys/rotate') {
                revoked.add(key);
                const created = newKey();
                keys.push(created.api_key);
                return answer(201, created);
            }
            if (request.url === '/v1/push') {
                const results = {};
                for (const item of data.tokens) {
                    const token = typeof item === 'string' ? item : item.token;
                    if (token === 'f'.repeat(64)) {
                        results[token] = { status: 'unregistered', reason: 'Unregistered' };
                    } else {
                        results[token] = { status: 'sent' };
                        notifications.push({ token, platform: typeof item === 'string' ? 'ios' : item.platform, key, title: data.title, body: data.body });
                    }
                }
                return answer(200, { results, quota: { notifications_per_day: 500, used: 1, remaining: 499 } });
            }
            return answer(404, { error: 'Not found', code: 404 });
        });
    });

    return new Promise(resolve => {
        server.listen(Number(process.env.E2E_PUSH_PORT), '127.0.0.1', () => {
            resolve({
                url: `http://127.0.0.1:${server.address().port}`,
                keys,
                notifications,
                close: () => new Promise(done => server.close(() => done())),
            });
        });
    });
}

module.exports = { startFakePushRelay };
