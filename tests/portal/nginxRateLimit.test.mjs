// Rate limit of the login routes in nginx (docker/nginx.conf), with the address of the
// client behind the trusted reverse proxies (TRUSTED_PROXIES, docker/nginx-real-ip.sh)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const read = (...path) => readFileSync(join(ROOT, ...path), 'utf8');
const conf = read('docker', 'nginx.conf');
const API_CONF = '/etc/nginx/carbure/api.conf';
const REAL_IP_CONF = '/run/carbure/nginx-real-ip.conf';

/**
 * Body of a location of nginx.conf (no nested block)
 * @param {string} spec - What follows "location " ("/api/", "~ ^/api/...$")
 * @returns {string} The directives between the braces
 */
function location(spec) {
    const start = conf.indexOf(`    location ${spec} {\n`);
    assert.notEqual(start, -1, `location ${spec}`);
    return conf.slice(start, conf.indexOf('\n    }\n', start)).split('\n').slice(1).join('\n');
}

/**
 * Directives of a block, without the comments and the empty lines
 * @param {string} text - Lines of nginx configuration
 * @returns {string[]} The directives
 */
function directives(text) {
    return text.split('\n').map(line => line.replace(/#.*/, '').trim()).filter(Boolean);
}

/**
 * Runs docker/nginx-real-ip.sh as the entrypoint does
 * @param {string|undefined} trusted - TRUSTED_PROXIES (undefined: unset)
 * @returns {{status: number, stdout: string, stderr: string}}
 */
function realIp(trusted) {
    const env = { PATH: process.env.PATH };
    if (trusted !== undefined) {
        env.TRUSTED_PROXIES = trusted;
    }
    return spawnSync('sh', [join(ROOT, 'docker', 'nginx-real-ip.sh')], { env, encoding: 'utf8' });
}

const loginLocation = conf.match(/^ {4}location ~ (\S+) \{$/m);

test('the login routes are limited per client, with a 429 in the format of the API', () => {
    assert.match(conf, /^limit_req_zone \$binary_remote_addr zone=carbure_login:10m rate=30r\/m;$/m);
    assert.ok(loginLocation, 'a regex location for the login routes');
    const body = directives(location(`~ ${loginLocation[1]}`));
    assert.ok(body.includes('limit_req zone=carbure_login burst=10 nodelay;'));
    assert.ok(body.includes('limit_req_status 429;'));
    assert.ok(body.includes('error_page 429 = @carbure_too_many_logins;'));
    const error = location('@carbure_too_many_logins');
    const json = JSON.parse(error.match(/return 429 '([^']+)';/)[1]);
    assert.equal(json.code, 429);
    assert.match(error, /add_header Retry-After 60 always;/);
});

test('the limited routes are exactly the login ones', () => {
    // nginx regexes (PCRE) of the conf are also valid JavaScript regexes
    const regex = new RegExp(loginLocation[1]);
    for (const path of ['/api/user/login', '/api/user/login/mfa', '/api/user/login/passkey',
        '/api/user/login/passkey/options', '/api/oauth/token']) {
        assert.match(path, regex);
    }
    for (const path of ['/api/user/me', '/api/user/login/other', '/api/user/loginx', '/api/user/login/passkey/x',
        '/api/user/passkey', '/api/user/passkey/options', '/api/oauth/authorize',
        '/api/oauth/register', '/api/oauth/token/x', '/portal/api/user/login', '/api/health']) {
        assert.doesNotMatch(path, regex);
    }
});

test('the login routes keep the settings of the API: one shared file, never a copy', () => {
    assert.deepEqual(directives(location('/api/')), [`include ${API_CONF};`]);
    const login = directives(location(`~ ${loginLocation[1]}`));
    assert.deepEqual(login.filter(line => !line.startsWith('limit_req') && !line.startsWith('error_page')),
        [`include ${API_CONF};`]);
    const api = directives(read('docker', 'nginx-api.conf'));
    for (const line of ['include fastcgi_params;', 'fastcgi_param SCRIPT_FILENAME $document_root/src/api.php;',
        'fastcgi_pass 127.0.0.1:9000;', 'fastcgi_read_timeout 3600s;', 'fastcgi_buffering off;', 'gzip off;']) {
        assert.ok(api.includes(line), line);
    }
    // Nowhere else in nginx.conf: only through the shared file
    assert.doesNotMatch(conf, /fastcgi_read_timeout 3600s|fastcgi_buffering off/);
    assert.match(read('docker', 'Dockerfile'), new RegExp(`^COPY docker/nginx-api\\.conf ${API_CONF}$`, 'm'));
});

test('the address of the client comes from the file written by the entrypoint', () => {
    assert.match(conf, /^ {4}include \/run\/carbure\/nginx-real-ip\*\.conf;$/m);
    assert.match(read('docker', 'entrypoint.sh'), new RegExp(`^sh "\\$APP/docker/nginx-real-ip\\.sh" > ${REAL_IP_CONF}$`, 'm'));
});

test('the private networks and the loopback are the trusted proxies by default', () => {
    const { status, stdout } = realIp(undefined);
    assert.equal(status, 0);
    assert.deepEqual(directives(stdout), [
        'set_real_ip_from 127.0.0.0/8;', 'set_real_ip_from 10.0.0.0/8;', 'set_real_ip_from 172.16.0.0/12;',
        'set_real_ip_from 192.168.0.0/16;', 'set_real_ip_from ::1/128;', 'set_real_ip_from fc00::/7;',
        'real_ip_header X-Forwarded-For;', 'real_ip_recursive on;'
    ]);
});

test('TRUSTED_PROXIES chooses the proxies, or none', () => {
    const { status, stdout } = realIp('192.168.1.10, 172.18.0.0/16 2001:db8::/32');
    assert.equal(status, 0);
    assert.deepEqual(directives(stdout), ['set_real_ip_from 192.168.1.10;', 'set_real_ip_from 172.18.0.0/16;',
        'set_real_ip_from 2001:db8::/32;', 'real_ip_header X-Forwarded-For;', 'real_ip_recursive on;']);
    // Carbure published without a proxy: X-Forwarded-For is never trusted
    for (const none of ['', 'none', '  ']) {
        const result = realIp(none);
        assert.equal(result.status, 0);
        assert.deepEqual(directives(result.stdout), [], JSON.stringify(none));
    }
});

test('an invalid entry of TRUSTED_PROXIES stops the container', () => {
    for (const wrong of ['192.168.1', '192.168.1.256', '10.0.0.0/33', '10.0.0.0/', 'fc00::/129', 'proxy.lan',
        '*', '10.0.0.1;', '10.0.0.0/8 all']) {
        const { status, stderr } = realIp(wrong);
        assert.equal(status, 1, wrong);
        assert.match(stderr, /invalid (address|network) in TRUSTED_PROXIES/, wrong);
    }
});
