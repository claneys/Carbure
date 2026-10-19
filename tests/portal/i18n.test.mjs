// Translations of the portal: the loader (portal/i18n.js, a plain script of the <head>)
// and the JSON files of the languages (portal/i18n/<locale>.json)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const source = readFileSync(new URL('../../portal/i18n.js', import.meta.url), 'utf8');

/** Languages that have a file in portal/i18n/ */
const FILES = readdirSync(new URL('../../portal/i18n/', import.meta.url)).filter(name => name.endsWith('.json')).map(name => name.slice(0, -5));

/** Texts of the languages, read from the JSON files */
const TEXTS = Object.fromEntries(FILES.map(locale => [
    locale, JSON.parse(readFileSync(new URL(`../../portal/i18n/${locale}.json`, import.meta.url), 'utf8'))
]));

/**
 * Runs i18n.js in a fake page
 * @param {{stored?: string|null, language?: string, search?: string, files?: Object, health?: Object|null}} options
 *        files: answer by language (an object, or a number = HTTP error); health: body of /api/health (null = error)
 * @returns {{i18n: Object, html: Object, storage: Map, requests: Array, warnings: Array, errors: Array, reloads: Function}}
 */
function load({ stored = null, language = 'fr-FR', search = '', files = TEXTS, health = { version: '1.4.0' } } = {}) {
    const storage = new Map(stored === null ? [] : [['locale', stored]]);
    const html = { lang: 'fr' };
    const requests = [];
    const warnings = [];
    const errors = [];
    let reloads = 0;
    const response = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
    const context = {
        module: { exports: {} },
        URL,
        URLSearchParams,
        Intl,
        navigator: { language },
        localStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, String(value)) },
        location: { href: 'https://carbure.example.com/portal/', search, reload: () => { reloads++; } },
        document: { documentElement: html },
        console: { warn: (...args) => warnings.push(args.join(' ')), error: (...args) => errors.push(args.join(' ')), log: () => {} },
        fetch: async (url, options = {}) => {
            requests.push({ url, options });
            if (url.endsWith('/api/health')) {
                return health ? response(health) : response({ error: 'down' }, 500);
            }
            const locale = new URL(url).pathname.match(/i18n\/(\w+)\.json$/)[1];
            const answer = files[locale];
            return typeof answer === 'number' || answer === undefined ? response(null, answer || 404) : response(answer);
        }
    };
    vm.runInNewContext(source, context);
    return { i18n: context.module.exports, html, storage, requests, warnings, errors, reloads: () => reloads };
}

/**
 * Keys of the texts of a language, nested ones with dots (months.1)
 * @param {Object} texts - Texts
 * @param {string} prefix - Prefix of the nested keys
 * @param {Object<string, string>} flat - Keys found so far
 * @returns {Object<string, string>} Text by key
 */
function flatten(texts, prefix = '', flat = {}) {
    for (const [key, value] of Object.entries(texts)) {
        if (typeof value === 'object' && value !== null) {
            flatten(value, `${prefix}${key}.`, flat);
        } else {
            flat[`${prefix}${key}`] = value;
        }
    }
    return flat;
}

/** Suffixes of the plural forms (categories of Intl.PluralRules) */
const PLURAL = /_(zero|one|two|few|many|other)$/;

/**
 * Key without its plural suffix, when it is a plural form (the language has <base>_other)
 * @param {string} key - Key
 * @param {Object<string, string>} flat - Texts of the language
 * @returns {string}
 */
function baseKey(key, flat) {
    const base = key.replace(PLURAL, '');
    return base !== key && `${base}_other` in flat ? base : key;
}

/**
 * Texts of a language grouped by base key (the plural forms together)
 * @param {Object} texts - Texts of the language
 * @returns {Map<string, string[]>}
 */
function grouped(texts) {
    const flat = flatten(texts);
    const groups = new Map();
    for (const [key, value] of Object.entries(flat)) {
        const base = baseKey(key, flat);
        groups.set(base, [...(groups.get(base) || []), value]);
    }
    return groups;
}

const placeholders = values => [...new Set(values.flatMap(value => [...String(value).matchAll(/\{(\w+)\}/g)].map(m => m[1])))].sort();

// --- The JSON files ---

test('every language has the keys of the reference language, and no other one', () => {
    const reference = [...grouped(TEXTS.fr).keys()].sort();
    for (const [locale, texts] of Object.entries(TEXTS)) {
        const keys = [...grouped(texts).keys()].sort();
        assert.deepEqual(keys.filter(key => !reference.includes(key)), [], `keys of ${locale} unknown in fr`);
        assert.deepEqual(reference.filter(key => !keys.includes(key)), [], `keys of fr missing in ${locale}`);
    }
});

test('a plural key has its _other form and no plain form in every language', () => {
    for (const [locale, texts] of Object.entries(TEXTS)) {
        const flat = flatten(texts);
        for (const key of Object.keys(flat).filter(key => PLURAL.test(key))) {
            const base = key.replace(PLURAL, '');
            assert.ok(`${base}_other` in flat, `${locale}: ${key} without ${base}_other`);
            assert.ok(!(base in flat), `${locale}: ${base} is both plain and plural`);
        }
    }
});

test('the {placeholders} of a key are the same in every language', () => {
    const reference = grouped(TEXTS.fr);
    for (const [locale, texts] of Object.entries(TEXTS)) {
        for (const [key, values] of grouped(texts)) {
            assert.deepEqual(placeholders(values), placeholders(reference.get(key) || []), `${locale}: {placeholders} of ${key}`);
        }
    }
});

test('no empty text, and no "(s)" left instead of a plural form', () => {
    for (const [locale, texts] of Object.entries(TEXTS)) {
        for (const [key, value] of Object.entries(flatten(texts))) {
            assert.equal(typeof value, 'string', `${locale}: ${key} is not a text`);
            assert.notEqual(value.trim(), '', `${locale}: ${key} is empty`);
            assert.doesNotMatch(value, /\(e?s\)/, `${locale}: ${key} uses "(s)": write ${key}_one / ${key}_other`);
        }
    }
});

test('the JSON files keep one format: 4-space indentation, final newline', () => {
    for (const locale of Object.keys(TEXTS)) {
        const raw = readFileSync(new URL(`../../portal/i18n/${locale}.json`, import.meta.url), 'utf8');
        assert.equal(raw, JSON.stringify(TEXTS[locale], null, 4) + '\n', `portal/i18n/${locale}.json`);
    }
});

test('every language of the loader has its file', () => {
    assert.deepEqual([...load().i18n.LOCALES].sort(), Object.keys(TEXTS).sort());
    assert.deepEqual([...load().i18n.LOCALES].sort(), ['de', 'en', 'es', 'fr', 'it', 'pt']);
});

test('the language selectors offer every language, named in its own language', () => {
    const html = readFileSync(new URL('../../portal/index.html', import.meta.url), 'utf8');
    const names = { fr: 'Français', en: 'English', es: 'Español', it: 'Italiano', de: 'Deutsch', pt: 'Português' };
    for (const id of ['setupLanguage', 'profile-language']) {
        const select = html.match(new RegExp(`<select id="${id}"[^>]*>([\\s\\S]*?)</select>`))[1];
        const options = [...select.matchAll(/<option value="(\w+)" lang="(\w+)">([^<]+)<\/option>/g)].map(m => [m[1], m[2], m[3]]);
        assert.deepEqual(options, Object.entries(names).map(([locale, name]) => [locale, locale, name]), id);
    }
});

// --- t(): fallback, plurals, parameters ---

const DICTIONARIES = {
    fr: { hello: 'Bonjour {name}', onlyFr: 'Seulement en français', item_one: '{count} élément', item_other: '{count} éléments', months: { 1: 'janvier' }, plain: '{count} fois' },
    en: { hello: 'Hello {name}', item_one: '{count} item', item_other: '{count} items', months: { 1: 'January' }, plain: '{count} times' }
};

test('translate: active language, then the reference one, then the key itself', () => {
    const { translate } = load().i18n;
    assert.equal(translate(DICTIONARIES, ['en', 'fr'], 'hello', { name: 'Ann' }), 'Hello Ann');
    assert.equal(translate(DICTIONARIES, ['en', 'fr'], 'onlyFr'), 'Seulement en français');
    assert.equal(translate(DICTIONARIES, ['en', 'fr'], 'missing.key'), 'missing.key');
    assert.equal(translate(DICTIONARIES, ['en', 'fr'], 'months.1'), 'January');
});

test('translate: the plural form of the language chosen by count', () => {
    const { translate } = load().i18n;
    const item = (locale, count) => translate(DICTIONARIES, [locale], 'item', { count });
    // French: 0 and 1 are singular; English: only 1
    assert.equal(item('fr', 0), '0 élément');
    assert.equal(item('fr', 1), '1 élément');
    assert.equal(item('fr', 2), '2 éléments');
    assert.equal(item('en', 0), '0 items');
    assert.equal(item('en', 1), '1 item');
    assert.equal(item('en', 3), '3 items');
    // A count given as a string (from the API), and the many category of French: _other
    assert.equal(item('en', '1'), '1 item');
    assert.equal(item('fr', 1000000), '1000000 éléments');
    // No count: the plain key (missing here); no plural form: the plain key
    assert.equal(translate(DICTIONARIES, ['en'], 'item'), 'item');
    assert.equal(translate(DICTIONARIES, ['en'], 'plain', { count: 1 }), '1 times');
});

test('translate: the parameters replace the {placeholders}, unknown ones are kept', () => {
    const { translate } = load().i18n;
    assert.equal(translate(DICTIONARIES, ['fr'], 'hello', {}), 'Bonjour {name}');
    assert.equal(translate(DICTIONARIES, ['fr'], 'hello', { name: null }), 'Bonjour {name}');
    assert.equal(translate(DICTIONARIES, ['fr'], 'hello', { name: 0 }), 'Bonjour 0');
});

test('the texts of the portal with a count: singular and plural', () => {
    const { translate } = load().i18n;
    assert.equal(translate(TEXTS, ['fr'], 'transactionCount', { count: 1 }), '1 transaction');
    assert.equal(translate(TEXTS, ['fr'], 'transactionCount', { count: 6 }), '6 transactions');
    assert.equal(translate(TEXTS, ['en'], 'rulesCount', { count: 0 }), '0 rules');
    assert.equal(translate(TEXTS, ['en'], 'rulesCount', { count: 1 }), '1 rule');
    // Only 1 is singular in Spanish, Italian, German and European Portuguese (Intl pt-PT)
    for (const locale of ['es', 'it', 'de', 'pt']) {
        assert.notEqual(translate(TEXTS, [locale], 'transactionCount', { count: 1 }), translate(TEXTS, [locale], 'transactionCount', { count: 2 }).replace('2', '1'), locale);
        assert.equal(translate(TEXTS, [locale], 'transactionCount', { count: 0 }), translate(TEXTS, [locale], 'transactionCount', { count: 2 }).replace('2', '0'), locale);
    }
    assert.equal(translate(TEXTS, ['pt'], 'transactionCount', { count: 0 }), '0 transações');
    assert.equal(translate(TEXTS, ['de'], 'transactionCount', { count: 1 }), '1 Transaktion');
});

test('intlLocale: the locale of Intl of a language, European Portuguese for pt', () => {
    const { intlLocale } = load().i18n;
    assert.equal(intlLocale('pt'), 'pt-PT');
    assert.equal(intlLocale('de'), 'de');
    assert.equal(intlLocale('fr'), 'fr');
});

// --- loadLocale(), t(), setLocale() in the page ---

test('loadLocale: the active language and the reference one, for the version of the server', async () => {
    const page = load({ stored: 'en' });
    assert.equal(await page.i18n.loadLocale(), 'en');
    const urls = page.requests.map(request => request.url);
    assert.equal(urls[0], 'https://carbure.example.com/api/health');
    assert.deepEqual(urls.slice(1).sort(), ['https://carbure.example.com/portal/i18n/en.json?v=1.4.0', 'https://carbure.example.com/portal/i18n/fr.json?v=1.4.0']);
    assert.equal(page.requests[1].options.cache, 'default');
    assert.equal(page.html.lang, 'en');
    assert.equal(page.i18n.currentLocale(), 'en');
    assert.equal(page.i18n.serverVersion(), '1.4.0');
    assert.equal(page.i18n.t('tabRules'), 'Rules');
    assert.equal(page.i18n.t('transactionCount', { count: 1 }), '1 transaction');
});

test('loadLocale: only the reference language when it is the active one (browser in French)', async () => {
    const page = load({ language: 'fr-CA' });
    assert.equal(await page.i18n.loadLocale(), 'fr');
    assert.deepEqual(page.requests.map(request => request.url).filter(url => url.includes('i18n/')), ['https://carbure.example.com/portal/i18n/fr.json?v=1.4.0']);
    assert.equal(page.html.lang, 'fr');
});

test('loadLocale: an English browser without a stored choice is in English', async () => {
    const page = load({ language: 'en-US' });
    assert.equal(await page.i18n.loadLocale(), 'en');
});

test('loadLocale: the browser language when the portal has it, French otherwise', async () => {
    for (const [language, locale] of [['es-ES', 'es'], ['it-IT', 'it'], ['de-AT', 'de'], ['pt-BR', 'pt'], ['PT', 'pt'], ['nl-NL', 'fr'], ['', 'fr']]) {
        const page = load({ language });
        assert.equal(await page.i18n.loadLocale(), locale, language);
    }
});

test('loadLocale: <html lang> of European Portuguese is pt-PT', async () => {
    const page = load({ stored: 'pt' });
    assert.equal(await page.i18n.loadLocale(), 'pt');
    assert.equal(page.html.lang, 'pt-PT');
    assert.equal(page.i18n.t('transactionCount', { count: 0 }), '0 transações');
    assert.equal(page.i18n.t('transactionCount', { count: 1 }), '1 transação');
    const page2 = load({ stored: 'de' });
    assert.equal(await page2.i18n.loadLocale(), 'de');
    assert.equal(page2.html.lang, 'de');
    assert.equal(page2.i18n.t('tabAccounts'), 'Konten');
});

test('loadLocale: without the version of the server, the cache is revalidated', async () => {
    const page = load({ stored: 'fr', health: null });
    await page.i18n.loadLocale();
    const request = page.requests.find(r => r.url.includes('i18n/'));
    assert.equal(request.url, 'https://carbure.example.com/portal/i18n/fr.json?v=dev');
    assert.equal(request.options.cache, 'no-cache');
    assert.equal(page.i18n.serverVersion(), null);
    assert.equal(page.i18n.serverDemo(), false);
});

test('loadLocale: a demonstration server is told by GET /api/health', async () => {
    const page = load({ health: { version: '1.6.0', demo: true } });
    await page.i18n.loadLocale();
    assert.equal(page.i18n.serverDemo(), true);
    const regular = load();
    await regular.i18n.loadLocale();
    assert.equal(regular.i18n.serverDemo(), false);
});

test('loadLocale: the currency of the instance is told by GET /api/health', async () => {
    const page = load({ health: { version: '1.9.0', currency: 'CHF' } });
    await page.i18n.loadLocale();
    assert.equal(page.i18n.serverCurrency(), 'CHF');
    // Unknown or invalid: euros
    for (const health of [null, { version: '1.8.0' }, { currency: 'chf' }, { currency: '<b>' }]) {
        const other = load({ health });
        await other.i18n.loadLocale();
        assert.equal(other.i18n.serverCurrency(), 'EUR');
    }
});

test('loadLocale: a language that cannot be loaded falls back to the reference one', async () => {
    const page = load({ stored: 'en', files: { fr: TEXTS.fr, en: 500 } });
    assert.equal(await page.i18n.loadLocale(), 'fr');
    assert.equal(page.html.lang, 'fr');
    assert.equal(page.i18n.currentLocale(), 'fr');
    assert.equal(page.i18n.t('tabRules'), 'Règles');
    assert.equal(page.errors.length, 1);
});

test('loadLocale: fails when no language can be loaded', async () => {
    const page = load({ stored: 'en', files: { fr: 404, en: 404 } });
    await assert.rejects(page.i18n.loadLocale(), /No translation could be loaded/);
    // t() still answers (the key)
    assert.equal(page.i18n.t('tabRules'), 'tabRules');
});

test('t: a key missing from the active language uses the reference one, reported once in debug mode', async () => {
    const page = load({ stored: 'en', search: '?debug=true', files: { fr: { a: 'A fr', b: 'B fr' }, en: { b: 'B en' } } });
    await page.i18n.loadLocale();
    assert.equal(page.i18n.t('a'), 'A fr');
    assert.equal(page.i18n.t('a'), 'A fr');
    assert.equal(page.i18n.t('b'), 'B en');
    assert.equal(page.i18n.t('nowhere'), 'nowhere');
    assert.deepEqual(page.warnings, ['[i18n] Missing translation "a" in en (fr text shown)', '[i18n] Missing translation "nowhere" in en']);
});

test('t: nothing reported without the debug mode', async () => {
    const page = load({ stored: 'en', files: { fr: { a: 'A fr' }, en: {} } });
    await page.i18n.loadLocale();
    assert.equal(page.i18n.t('a'), 'A fr');
    assert.deepEqual(page.warnings, []);
});

test('setLocale: a language of the portal is stored and the page reloaded, another one ignored', () => {
    const page = load();
    page.i18n.setLocale('xx');
    assert.equal(page.reloads(), 0);
    page.i18n.setLocale('en');
    assert.equal(page.storage.get('locale'), 'en');
    assert.equal(page.reloads(), 1);
});

test('an unknown stored language is ignored', async () => {
    const page = load({ stored: 'xx', language: 'en-GB' });
    assert.equal(await page.i18n.loadLocale(), 'en');
});

// --- Keys used by the portal ---

const ROOT = fileURLToPath(new URL('../..', import.meta.url));

/**
 * Files of a directory, recursively, with one of the extensions (vendor excluded)
 * @param {string} dir - Directory (relative to the project)
 * @param {string[]} extensions - Extensions, with the dot
 * @returns {string[]} Paths relative to the project
 */
function files(dir, extensions) {
    return readdirSync(join(ROOT, dir)).flatMap(name => {
        const path = join(dir, name);
        if (statSync(join(ROOT, path)).isDirectory()) {
            return name === 'vendor' ? [] : files(path, extensions);
        }
        return extensions.some(ext => name.endsWith(ext)) ? [path] : [];
    });
}

/**
 * Contents of files
 * @param {string[]} paths - Paths relative to the project
 * @returns {Array<{path: string, code: string}>}
 */
const read = paths => paths.map(path => ({ path, code: readFileSync(join(ROOT, path), 'utf8') }));

/** Sources of the portal that translate (the loader excluded) */
const PORTAL = read(files('portal', ['.js', '.html']).filter(path => !path.endsWith('i18n.js')));

/** The end-to-end specs and their fixtures, which read the texts with tr() */
const SPECS = read([...files('tests/e2e/specs', ['.js']), 'tests/e2e/fixtures.js']);

/**
 * Prefixes of the keys built by the code: t('syncStep_' + step), t(`months.${month}`),
 * const key = 'configHelp_' + name... (a quoted word ending with _ or . followed by +,
 * or a template starting with one before ${)
 */
const DYNAMIC_PREFIXES = [...new Set(PORTAL.flatMap(({ code }) => [
    ...[...code.matchAll(/(['"])(\w+[_.])\1\s*\+/g)].map(m => m[2]),
    ...[...code.matchAll(/`(\w+[_.])\$\{/g)].map(m => m[1])
]))];

/**
 * Keys used without a quoted literal of their own, that the scan cannot see (keep it short:
 * prefer a literal in the code, like the keys of the AI agents or of the tabs)
 */
const INDIRECT_KEYS = [];

/** Keys of the reference language, plural forms grouped (transactionCount for transactionCount_one) */
const KEYS = [...grouped(TEXTS.fr).keys()];

test('every key translated by the portal and the specs exists', () => {
    const missing = [];
    for (const { path, code } of [...PORTAL, ...SPECS]) {
        for (const [, , key] of code.matchAll(/\b(?:t|tr|\$t)\(\s*(['"])([\w.]+)\1\s*[,)]/g)) {
            if (!KEYS.includes(key)) {
                missing.push(`${path}: ${key}`);
            }
        }
    }
    assert.deepEqual(missing, []);
});

test('every prefix of a key built with t() has keys', () => {
    assert.ok(DYNAMIC_PREFIXES.includes('syncStep_') && DYNAMIC_PREFIXES.includes('months.'), DYNAMIC_PREFIXES.join(', '));
    const translated = [...new Set(PORTAL.flatMap(({ code }) => [
        ...[...code.matchAll(/\bt\(\s*(['"])(\w+[_.])\1\s*\+/g)].map(m => m[2]),
        ...[...code.matchAll(/\bt\(\s*`(\w+[_.])\$\{/g)].map(m => m[1])
    ]))];
    assert.deepEqual(translated.filter(prefix => !KEYS.some(key => key.startsWith(prefix))), []);
});

test('no key is unused by the portal', () => {
    const literals = new Set(PORTAL.flatMap(({ code }) => [...code.matchAll(/(['"`])([\w.]+)\1/g)].map(m => m[2])));
    const unused = KEYS.filter(key => !literals.has(key)
        && !DYNAMIC_PREFIXES.some(prefix => key.startsWith(prefix))
        && !INDIRECT_KEYS.includes(key));
    assert.deepEqual(unused, [], 'keys of portal/i18n/*.json used nowhere: remove them (or list them in INDIRECT_KEYS)');
});
