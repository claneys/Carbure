#!/usr/bin/env node
// Converts the translations of the old format (portal/i18n.js: one `const i18n = { fr: {...},
// en: {...} }` object) into one JSON file per language (portal/i18n/<locale>.json).
//
//   node tools/i18n-split.mjs <old-i18n.js>            full conversion (overwrites the JSON files)
//   node tools/i18n-split.mjs --merge <old-i18n.js>    adds only the keys missing from the JSON files
//
// --merge is meant for a branch started before the split: it brings the keys the branch
// added to its i18n.js into the JSON files, without touching the existing keys
// (git show <branch>:portal/i18n.js > /tmp/old-i18n.js, then run it on that file).
//
// The strings with "(s)" known at the time of the split become <key>_one / <key>_other pairs
// (plurals chosen by Intl.PluralRules, see portal/i18n.js); any other "(s)" is reported.
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const OUTPUT = `${ROOT}portal/i18n`;

/** Keys used nowhere in the portal at the split, dropped (see tests/portal/i18n.test.mjs) */
const UNUSED = ['ruleUpdated', 'bankNotConfigured', 'syncTitle', 'syncHelp', 'syncStart', 'syncRunning', 'syncAccounts',
    'ruleAdded', 'filterLabel', 'budgetTitle', 'consumedLabel', 'progressLabel', 'overBudget', 'nearLimit', 'onTrack',
    'updateNotImplemented', 'errorMessage'];

/** Strings with "(s)" converted at the split into plural pairs, by key, then by language */
const PLURALS = {
    ruleSavedApplied: {
        fr: ['Règle enregistrée et appliquée à {count} transaction', 'Règle enregistrée et appliquée à {count} transactions'],
        en: ['Rule saved and applied to {count} transaction', 'Rule saved and applied to {count} transactions']
    },
    ruleCount: {
        fr: ['{count} transaction contient ce mot-clé, dont {categorized} dans cette catégorie', '{count} transactions contiennent ce mot-clé, dont {categorized} dans cette catégorie'],
        en: ['{count} transaction contains this keyword, {categorized} of them in this category', '{count} transactions contain this keyword, {categorized} of them in this category']
    },
    importSelectedCount: {
        fr: ['{count} sélectionnée', '{count} sélectionnées'],
        en: ['{count} selected', '{count} selected']
    },
    importDone: {
        fr: ['{count} transaction importée, {categorized} catégorisée', '{count} transactions importées (catégorisées : {categorized})'],
        en: ['{count} transaction imported, {categorized} categorized', '{count} transactions imported, {categorized} categorized']
    },
    schemaUpdateAvailable: {
        fr: ['Mise à jour de la base de données disponible ({count} étape). Sauvegardez votre base, puis appliquez-la.', 'Mise à jour de la base de données disponible ({count} étapes). Sauvegardez votre base, puis appliquez-la.'],
        en: ['A database update is available ({count} step). Back up your database, then apply it.', 'A database update is available ({count} steps). Back up your database, then apply it.']
    },
    accountsFollowedToast: {
        fr: ['{count} compte suivi', '{count} comptes suivis'],
        en: ['{count} account followed', '{count} accounts followed']
    },
    syncStepCount: {
        fr: ['{count} transaction', '{count} transactions'],
        en: ['{count} transaction', '{count} transactions']
    },
    syncStepResult: {
        fr: ['{count} reçue · {created} nouvelle', '{count} reçues · nouvelles : {created}'],
        en: ['{count} received · {created} new', '{count} received · {created} new']
    },
    rulesApplied: {
        fr: ['Règles appliquées à toute la base : {count} transaction recatégorisée', 'Règles appliquées à toute la base : {count} transactions recatégorisées'],
        en: ['Rules applied to the whole database: {count} transaction recategorized', 'Rules applied to the whole database: {count} transactions recategorized']
    },
    rulesCount: {
        fr: ['{count} règle', '{count} règles'],
        en: ['{count} rule', '{count} rules']
    },
    transactionCount: {
        fr: ['{count} transaction', '{count} transactions'],
        en: ['{count} transaction', '{count} transactions']
    },
    uncheckedCount: {
        fr: ['{count} non vérifiée', '{count} non vérifiées'],
        en: ['{count} unchecked', '{count} unchecked']
    },
    recurringCount: {
        fr: ['{count} récurrente', '{count} récurrentes'],
        en: ['{count} recurring', '{count} recurring']
    },
    searchTotal: {
        fr: ['Total de {count} transaction trouvée', 'Total des {count} transactions trouvées'],
        en: ['Total of the {count} transaction found', 'Total of the {count} transactions found']
    },
    budgetCopiedToast: {
        fr: ['{count} budget recopié', '{count} budgets recopiés'],
        en: ['{count} budget copied', '{count} budgets copied']
    },
    configChanges: {
        fr: ['{count} réglage modifié', '{count} réglages modifiés'],
        en: ['{count} setting changed', '{count} settings changed']
    }
};

/**
 * Translations of an i18n.js of the old format
 * @param {string} file - Path of the file
 * @returns {Object<string, Object>} The texts, by language
 */
function readOldFormat(file) {
    const source = readFileSync(file, 'utf8');
    if (!/\bconst i18n\s*=\s*\{/.test(source)) {
        throw new Error(`${file} is not an i18n.js of the old format (const i18n = { fr: {...}, en: {...} })`);
    }
    const context = { localStorage: { getItem: () => null, setItem: () => {} }, navigator: { language: 'fr' }, location: {} };
    vm.runInNewContext(`${source}\nthis.__i18n = i18n;`, context);
    return JSON.parse(JSON.stringify(context.__i18n));
}

/**
 * Texts of a language with the known "(s)" strings replaced by plural pairs, in the same
 * order, without the unused keys
 * @param {Object} texts - Texts of the language
 * @param {string} locale - Language
 * @returns {Object}
 */
function withPlurals(texts, locale) {
    const converted = {};
    for (const [key, value] of Object.entries(texts)) {
        if (UNUSED.includes(key)) {
            continue;
        }
        if (PLURALS[key] && PLURALS[key][locale]) {
            [converted[`${key}_one`], converted[`${key}_other`]] = PLURALS[key][locale];
        } else {
            converted[key] = value;
        }
    }
    return converted;
}

/**
 * Adds to the existing texts the keys they miss, each one after the key preceding it in
 * the source (at the end otherwise); a key whose plural pair exists is not missing
 * @param {Object} existing - Texts of the JSON file
 * @param {Object} source - Texts of the old format, plurals converted
 * @returns {{texts: Object, added: string[]}}
 */
function merge(existing, source) {
    const missing = Object.keys(source).filter(key => !(key in existing) && !(`${key}_other` in existing));
    const texts = { ...existing };
    const keys = Object.keys(texts);
    for (const key of missing) {
        const sourceKeys = Object.keys(source);
        const previous = sourceKeys.slice(0, sourceKeys.indexOf(key)).reverse().find(k => keys.includes(k));
        keys.splice(previous ? keys.indexOf(previous) + 1 : keys.length, 0, key);
        texts[key] = source[key];
    }
    return { texts: Object.fromEntries(keys.map(key => [key, texts[key]])), added: missing };
}

/**
 * Keys whose text still contains "(s)" (to convert into a plural pair by hand)
 * @param {Object} texts - Texts of a language
 * @param {string} prefix - Prefix of the nested keys
 * @returns {string[]}
 */
function remainingPluralMarks(texts, prefix = '') {
    return Object.entries(texts).flatMap(([key, value]) => (typeof value === 'object' && value !== null)
        ? remainingPluralMarks(value, `${prefix}${key}.`)
        : (/\(e?s\)/.test(String(value)) ? [`${prefix}${key}`] : []));
}

const args = process.argv.slice(2);
const mergeMode = args.includes('--merge');
const file = args.find(arg => !arg.startsWith('--'));
if (!file) {
    console.error('Usage: node tools/i18n-split.mjs [--merge] <old-i18n.js>');
    process.exit(2);
}

const languages = readOldFormat(file);
for (const [locale, texts] of Object.entries(languages)) {
    const target = `${OUTPUT}/${locale}.json`;
    let result = withPlurals(texts, locale);
    if (mergeMode && existsSync(target)) {
        const merged = merge(JSON.parse(readFileSync(target, 'utf8')), result);
        result = merged.texts;
        console.log(`${locale}: ${merged.added.length} key(s) added${merged.added.length ? ': ' + merged.added.join(', ') : ''}`);
    } else {
        console.log(`${locale}: ${Object.keys(result).length} keys written`);
    }
    writeFileSync(target, JSON.stringify(result, null, 4) + '\n', 'utf8');
    for (const key of remainingPluralMarks(result)) {
        console.warn(`${locale}: "${key}" still contains "(s)": use ${key}_one / ${key}_other with {count}`);
    }
}
