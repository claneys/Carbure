// Web App Manifest of the portal (portal/manifest.webmanifest): the portal can be
// installed as an app (Chrome/Edge "Install", Safari "Add to Dock", home screen)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const PORTAL = new URL('../../portal/', import.meta.url);
const manifest = JSON.parse(readFileSync(new URL('manifest.webmanifest', PORTAL), 'utf8'));
const html = readFileSync(new URL('index.html', PORTAL), 'utf8');

/**
 * Width and height of a PNG file, read in its IHDR chunk
 * @param {URL} url - The file
 * @returns {{width: number, height: number}}
 */
export function pngSize(url) {
    const data = readFileSync(url);
    assert.equal(data.subarray(0, 8).toString('hex'), '89504e470d0a1a0a', `${fileURLToPath(url)} is a PNG`);
    assert.equal(data.subarray(12, 16).toString('ascii'), 'IHDR');
    return { width: data.readUInt32BE(16), height: data.readUInt32BE(20) };
}

test('the manifest names the app and opens it in its own window', () => {
    assert.equal(manifest.name, 'Carbure');
    assert.equal(manifest.short_name, 'Carbure');
    assert.ok(manifest.description.length > 0);
    assert.equal(manifest.lang, 'fr');
    assert.equal(manifest.display, 'standalone');
    assert.deepEqual(manifest.categories, ['finance']);
    for (const color of [manifest.theme_color, manifest.background_color]) {
        assert.match(color, /^#[0-9a-f]{6}$/i);
    }
});

test('the manifest URLs are relative: the portal works under any path', () => {
    const base = 'https://carbure.example.com/some/path/portal/manifest.webmanifest';
    assert.equal(new URL(manifest.start_url, base).href, 'https://carbure.example.com/some/path/portal/');
    // The start URL is inside the scope of the app
    assert.ok(new URL(manifest.start_url, base).href.startsWith(new URL(manifest.scope, base).href));
    // No id: it is the start URL (an id is resolved against the origin only)
    assert.equal(manifest.id, undefined);
    for (const url of [manifest.start_url, manifest.scope, ...manifest.icons.map(icon => icon.src)]) {
        assert.doesNotMatch(url, /^(?:[a-z][a-z0-9+.-]*:)?\/\//i, url);
        assert.doesNotMatch(url, /^\//, url);
    }
});

test('the icons of the manifest exist with their declared sizes', () => {
    const purposes = new Set();
    for (const icon of manifest.icons) {
        const file = new URL(icon.src, PORTAL);
        assert.ok(existsSync(file), icon.src);
        assert.equal(icon.type, 'image/png');
        const { width, height } = pngSize(file);
        assert.equal(`${width}x${height}`, icon.sizes, icon.src);
        purposes.add(`${icon.purpose} ${icon.sizes}`);
    }
    // Chromium needs 192 and 512 to install the app; Android masks the maskable one
    assert.ok(purposes.has('any 192x192') && purposes.has('any 512x512') && purposes.has('maskable 512x512'));
});

test('index.html links the manifest and the icon of the Apple devices', () => {
    assert.ok(html.includes('<link rel="manifest" href="manifest.webmanifest">'));
    assert.ok(html.includes('<meta name="apple-mobile-web-app-title" content="Carbure">'));
    const apple = html.match(/<link rel="apple-touch-icon" href="([^"]+)">/);
    assert.ok(apple, 'apple-touch-icon');
    assert.deepEqual(pngSize(new URL(apple[1], PORTAL)), { width: 180, height: 180 });
});

test('no service worker: nothing of the API (financial data, JWT) is cached', () => {
    const files = readdirSync(PORTAL, { recursive: true })
        .filter(name => /\.(?:m?js|html)$/.test(name) && !name.startsWith('vendor'));
    assert.ok(files.includes('app.js'));
    for (const name of files) {
        assert.doesNotMatch(readFileSync(new URL(name, PORTAL), 'utf8'), /serviceWorker\s*\.\s*register/, name);
    }
});
