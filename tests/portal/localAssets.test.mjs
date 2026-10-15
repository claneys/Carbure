// No external CDN: the portal and Swagger UI load every script, style, font and image
// from the project (portal/vendor, swagger/vendor), and the CSP of nginx allows only 'self'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const EXTERNAL = /^(?:[a-z][a-z0-9+.-]*:)?\/\//i;

/**
 * Files of a directory, recursively, with one of the extensions
 * @param {string} dir - Directory (relative to the project)
 * @param {string[]} extensions - Extensions, with the dot
 * @returns {string[]} Paths relative to the project
 */
function files(dir, extensions) {
    const found = [];
    for (const name of readdirSync(join(ROOT, dir))) {
        const path = join(dir, name);
        if (statSync(join(ROOT, path)).isDirectory()) {
            found.push(...files(path, extensions));
        } else if (extensions.some(ext => name.endsWith(ext))) {
            found.push(path);
        }
    }
    return found;
}

/**
 * Resources an HTML page loads from another site: src/href of the tags that load
 * something (not the links <a> the user may follow, nor <link rel="canonical">)
 * @param {string} html - The page
 * @returns {string[]}
 */
export function externalResourcesOfHtml(html) {
    const found = [];
    for (const [tag] of html.matchAll(/<(?:script|link|img|source|iframe|video|audio|embed|object)\b[^>]*>/gi)) {
        if (/^<link\b/i.test(tag) && /\brel=["']?(canonical|alternate|author|license|me|next|prev)\b/i.test(tag)) {
            continue;
        }
        for (const [, url] of tag.matchAll(/\b(?:src|href|data|poster)=["']([^"']+)["']/gi)) {
            if (EXTERNAL.test(url.trim())) {
                found.push(url);
            }
        }
    }
    return found;
}

/**
 * Resources a style sheet loads from another site (url(...) and @import)
 * @param {string} css - The style sheet
 * @returns {string[]}
 */
export function externalResourcesOfCss(css) {
    const urls = [...css.matchAll(/url\(\s*["']?([^"')]+)["']?\s*\)/gi)].map(m => m[1]);
    const imports = [...css.matchAll(/@import\s+["']([^"']+)["']/gi)].map(m => m[1]);
    return [...urls, ...imports].filter(url => EXTERNAL.test(url.trim()));
}

/**
 * Sources of a Content-Security-Policy directive
 * @param {string} csp - The policy
 * @param {string} directive - e.g. script-src
 * @returns {string[]}
 */
function cspSources(csp, directive) {
    const part = csp.split(';').map(p => p.trim()).find(p => p.startsWith(directive + ' '));
    return part ? part.split(/\s+/).slice(1) : [];
}

test('the detection of external resources', () => {
    assert.deepEqual(externalResourcesOfHtml('<script src="https://unpkg.com/x.js"></script><link rel="stylesheet" href="//cdn.example/x.css"><img src="vendor/a.png">'),
        ['https://unpkg.com/x.js', '//cdn.example/x.css']);
    assert.deepEqual(externalResourcesOfHtml('<a href="https://woob.tech/">woob</a><link rel="canonical" href="https://carbure.org/">'), []);
    assert.deepEqual(externalResourcesOfCss('@font-face{src:url(fonts/a.woff2)} a{background:url("https://x.example/b.png")} @import "https://fonts.example/c.css";'),
        ['https://x.example/b.png', 'https://fonts.example/c.css']);
    assert.deepEqual(externalResourcesOfCss('a{background:url(data:image/png;base64,AAAA)}'), []);
});

test('the pages of the portal and Swagger UI load nothing from another site', () => {
    const pages = [...files('portal', ['.html']), ...files('swagger', ['.html'])];
    assert.ok(pages.includes(join('portal', 'index.html')) && pages.includes(join('swagger', 'index.html')));
    for (const page of pages) {
        assert.deepEqual(externalResourcesOfHtml(readFileSync(join(ROOT, page), 'utf8')), [], relative(ROOT, join(ROOT, page)));
    }
});

test('the style sheets (vendored ones included) load nothing from another site', () => {
    for (const sheet of [...files('portal', ['.css']), ...files('swagger', ['.css'])]) {
        assert.deepEqual(externalResourcesOfCss(readFileSync(join(ROOT, sheet), 'utf8')), [], sheet);
    }
});

test('the scripts of the portal load no script, style, font or image from another site', () => {
    const asset = /["'`]((?:https?:)?\/\/[^"'`\s]+\.(?:m?js|css|woff2?|ttf|otf|png|jpe?g|gif|svg|webp|ico))(?:[?#][^"'`]*)?["'`]/gi;
    const scripts = files('portal', ['.js']).filter(f => !f.startsWith(join('portal', 'vendor')));
    for (const script of [...scripts, join('swagger', 'swagger-init.js')]) {
        const found = [...readFileSync(join(ROOT, script), 'utf8').matchAll(asset)].map(m => m[1]);
        assert.deepEqual(found, [], script);
    }
});

test('the CSP of nginx allows the resources of the project only', () => {
    const conf = readFileSync(join(ROOT, 'docker', 'nginx.conf'), 'utf8');
    const policies = [...conf.matchAll(/(?:set \$csp_portal|Content-Security-Policy) "([^"]+)"/g)].map(m => m[1]);
    assert.equal(policies.length, 2, 'the CSP of the portal and the one of Swagger UI');
    for (const csp of policies) {
        for (const directive of ['default-src', 'script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'manifest-src']) {
            const others = cspSources(csp, directive).filter(s => !["'self'", "'none'", "'unsafe-inline'", "'unsafe-eval'", 'data:'].includes(s));
            assert.deepEqual(others, [], `${directive} of "${csp}"`);
        }
    }
});
