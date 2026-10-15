// Translations of the Carbure portal: one JSON file per language, i18n/<locale>.json.
//
// loadLocale() fetches the active language (and the reference language, French, as the
// fallback of the missing keys) before the Vue application is mounted (see app.js); t()
// then translates synchronously: active language -> reference language -> the key itself.
// Plurals: t(key, { count }) uses <key>_one / <key>_other... (categories of
// Intl.PluralRules) when the language has them, <key> otherwise.
//
// A plain script (no module): its functions are globals of the page, and it is also
// loaded by Node for the tests (module.exports at the end).

/** Languages of the portal (each one has its file i18n/<locale>.json) */
const LOCALES = ['fr', 'en'];

/** Reference language: complete, fallback of the missing keys */
const REFERENCE_LOCALE = 'fr';

/** Texts loaded, by language */
const i18n = {};

/** State of the loader: active language, version of the server, keys already reported */
const i18nState = { locale: null, version: null, debug: false, reported: new Set(), pluralRules: {} };

/**
 * Language before login: the browser one (the profile language applies after login)
 * @returns {string}
 */
function defaultLocale() {
    const language = (typeof navigator !== 'undefined' && navigator.language) || '';
    return language.toLowerCase().startsWith('en') ? 'en' : 'fr';
}

/**
 * Language chosen in this browser (localStorage), the browser one otherwise
 * @returns {string}
 */
function storedLocale() {
    let stored = null;
    try {
        stored = localStorage.getItem('locale');
    } catch (e) {
        stored = null;
    }
    return LOCALES.includes(stored) ? stored : defaultLocale();
}

/**
 * Active language: the one loaded by loadLocale(), the chosen one before
 * @returns {string}
 */
function currentLocale() {
    return i18nState.locale || storedLocale();
}

/**
 * Version of Carbure given by the server (GET /api/health) while loading, null if unknown
 * @returns {string|null}
 */
function serverVersion() {
    return i18nState.version;
}

/**
 * Languages searched for a key: the active one, then the reference one
 * @param {string} locale - Active language
 * @returns {string[]}
 */
function fallbackChain(locale) {
    return locale === REFERENCE_LOCALE ? [locale] : [locale, REFERENCE_LOCALE];
}

/**
 * Value of a key in the texts of a language (nested keys with dots: months.1)
 * @param {Object|undefined} texts - Texts of a language
 * @param {string} key - Key
 * @returns {*} The value, undefined if missing
 */
function lookup(texts, key) {
    return key.split('.').reduce((value, part) => (value === undefined || value === null ? undefined : value[part]), texts);
}

/**
 * The number deciding the plural form: params.count, if it is a number
 * @param {Object} params - Parameters of the text
 * @returns {number|null}
 */
function pluralCount(params) {
    const count = params ? params.count : undefined;
    if (count === undefined || count === null || count === '' || typeof count === 'boolean') {
        return null;
    }
    const number = Number(count);
    return Number.isFinite(number) ? number : null;
}

/**
 * Plural category of a number in a language (one, other, many...)
 * @param {string} locale - Language
 * @param {number} count - Number
 * @returns {string}
 */
function pluralCategory(locale, count) {
    try {
        if (!i18nState.pluralRules[locale]) {
            i18nState.pluralRules[locale] = new Intl.PluralRules(locale);
        }
        return i18nState.pluralRules[locale].select(count);
    } catch (e) {
        return 'other';
    }
}

/**
 * Text of a key in the first language of the chain that has it; with a count, the
 * plural form of the language (<key>_<category>, then <key>_other) before <key>
 * @param {Object<string, Object>} dictionaries - Texts, by language
 * @param {string[]} locales - Languages to search, in order
 * @param {string} key - Key
 * @param {Object} params - Parameters (count chooses the plural form)
 * @returns {*} The value, undefined if no language has it
 */
function resolveText(dictionaries, locales, key, params) {
    const count = pluralCount(params);
    for (const locale of locales) {
        const texts = dictionaries[locale];
        if (!texts) {
            continue;
        }
        const candidates = count === null ? [key] : [`${key}_${pluralCategory(locale, count)}`, `${key}_other`, key];
        for (const candidate of candidates) {
            const value = lookup(texts, candidate);
            if (value !== undefined && value !== null) {
                return value;
            }
        }
    }
    return undefined;
}

/**
 * Replaces the {placeholders} of a text by the parameters (unknown ones are kept)
 * @param {string} text - Text
 * @param {Object} params - Parameters
 * @returns {string}
 */
function formatText(text, params) {
    return text.replace(/\{(\w+)\}/g, (match, name) => (params && params[name] !== undefined && params[name] !== null) ? params[name] : match);
}

/**
 * Translation of a key in a chain of languages: the logic of t(), also used by the tests
 * (tests/e2e/fixtures.js) with the JSON files
 * @param {Object<string, Object>} dictionaries - Texts, by language
 * @param {string[]} locales - Languages to search, in order
 * @param {string} key - Key (nested keys with dots)
 * @param {Object} params - Values of the {placeholders}; count chooses the plural form
 * @returns {*} The text, the key itself if no language has it
 */
function translate(dictionaries, locales, key, params = {}) {
    const value = resolveText(dictionaries, locales, key, params);
    if (typeof value === 'string') {
        return formatText(value, params);
    }
    return value || key;
}

/**
 * Debug mode of the portal (?debug=true)
 * @returns {boolean}
 */
function i18nDebug() {
    try {
        return String(new URLSearchParams(location.search).get('debug')).toLowerCase() === 'true';
    } catch (e) {
        return false;
    }
}

/**
 * Reports once a key missing from the active language (debug mode)
 * @param {string} locale - Active language
 * @param {string} key - Key
 * @param {Object} params - Parameters
 */
function reportMissing(locale, key, params) {
    if (i18nState.reported.has(`${locale}:${key}`)) {
        return;
    }
    i18nState.reported.add(`${locale}:${key}`);
    const fallback = locale !== REFERENCE_LOCALE && resolveText(i18n, [REFERENCE_LOCALE], key, params) !== undefined;
    console.warn(`[i18n] Missing translation "${key}" in ${locale}` + (fallback ? ` (${REFERENCE_LOCALE} text shown)` : ''));
}

/**
 * Translated text of a key in the active language
 * @param {string} key - Key (nested keys with dots: months.1)
 * @param {Object} params - Values of the {placeholders}; count chooses the plural form
 * @returns {string} The text (the key itself if it is missing everywhere)
 */
function t(key, params = {}) {
    const locale = currentLocale();
    if (i18nState.debug && resolveText(i18n, [locale], key, params) === undefined) {
        reportMissing(locale, key, params);
    }
    return translate(i18n, fallbackChain(locale), key, params);
}

/**
 * Changes the language: stored in this browser, then the page reloads to load its texts
 * @param {string} locale - Language (one of LOCALES)
 */
function setLocale(locale) {
    if (LOCALES.includes(locale)) {
        localStorage.setItem('locale', locale);
        location.reload();
    }
}

/**
 * Version of Carbure (GET /api/health), to fetch the texts of this version and not those
 * kept in the cache of the browser; null if the server does not answer (installation)
 * @returns {Promise<string|null>}
 */
async function fetchServerVersion() {
    try {
        const response = await fetch(new URL('../api/health', location.href).href);
        const health = response.ok ? await response.json() : null;
        return health && typeof health.version === 'string' ? health.version : null;
    } catch (e) {
        return null;
    }
}

/**
 * Texts of a language: i18n/<locale>.json?v=<version>; without a released version (dev,
 * unknown) the cache of the browser is revalidated
 * @param {string} locale - Language
 * @param {string|null} version - Version of Carbure
 * @returns {Promise<Object>}
 */
async function fetchTexts(locale, version) {
    const url = new URL(`i18n/${locale}.json?v=${encodeURIComponent(version || 'dev')}`, location.href).href;
    const released = version && version !== 'dev';
    const response = await fetch(url, { cache: released ? 'default' : 'no-cache' });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status} for ${url}`);
    }
    const texts = await response.json();
    if (!texts || typeof texts !== 'object' || Array.isArray(texts)) {
        throw new Error(`Invalid translations in ${url}`);
    }
    return texts;
}

/**
 * Loads the texts of the active language and of the reference language (fallback),
 * before the application is mounted. A language that cannot be loaded falls back to the
 * reference language; <html lang> follows the language shown.
 * @returns {Promise<string>} The active language
 * @throws {Error} If no language could be loaded
 */
async function loadLocale() {
    const wanted = storedLocale();
    i18nState.debug = i18nDebug();
    i18nState.version = await fetchServerVersion();
    const locales = fallbackChain(wanted);
    const results = await Promise.allSettled(locales.map(locale => fetchTexts(locale, i18nState.version)));
    results.forEach((result, index) => {
        if (result.status === 'fulfilled') {
            i18n[locales[index]] = result.value;
        } else {
            console.error(`[i18n] ${locales[index]} not loaded:`, result.reason);
        }
    });
    const loaded = locales.find(locale => i18n[locale]);
    if (!loaded) {
        throw new Error('No translation could be loaded');
    }
    i18nState.locale = loaded;
    document.documentElement.lang = loaded;
    return loaded;
}

/**
 * Message shown when the texts cannot be loaded (written here: there is no translation
 * to show it with)
 */
function showLocaleError() {
    const message = document.createElement('p');
    message.className = 'load-error';
    message.setAttribute('role', 'alert');
    message.textContent = 'Le portail n\'a pas pu être chargé : rechargez la page. / The portal could not be loaded: reload the page.';
    document.body.prepend(message);
}

// Export for the tests run by Node
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { i18n, t, setLocale, loadLocale, translate, resolveText, currentLocale, defaultLocale, serverVersion, showLocaleError, LOCALES, REFERENCE_LOCALE };
}
