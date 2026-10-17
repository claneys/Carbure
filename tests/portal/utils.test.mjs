// utils/formatters.js and utils/categoryIcons.js
import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatAmount, formatDate, getTransactionIcon, getInsightIcon, isDebugMode } from '../../portal/utils/formatters.js';
import { materialIconFor, cssColorFor, badgeStyle, CATEGORY_ICONS, CATEGORY_COLORS } from '../../portal/utils/categoryIcons.js';

const spaces = text => text.replace(/\s/g, ' ');

test('formatAmount: two decimals in the locale', () => {
    assert.equal(spaces(formatAmount(1234.5, 'fr')), '1 234,50');
    assert.equal(formatAmount(-6.8, 'en'), '-6.80');
    assert.equal(formatAmount('82.4', 'en'), '82.40');
});

test('formatDate: ISO dates and MySQL datetimes', () => {
    assert.equal(formatDate('2026-10-03', 'fr'), '03 octobre 2026');
    // MySQL datetime: a space instead of the T
    assert.equal(formatDate('2026-10-03 15:00:00', 'en'), 'October 03, 2026');
});

test('getTransactionIcon: icon of the type of operation', () => {
    assert.equal(getTransactionIcon(7), 'credit_card');
    assert.equal(getTransactionIcon('1'), 'compare_arrows');
    assert.equal(getTransactionIcon(99), 'payment');
});

test('getInsightIcon: icon from the name, a default one otherwise', () => {
    assert.equal(getInsightIcon('EPARGNE'), 'savings');
    assert.equal(getInsightIcon('Loyer'), 'insights');
});

test('isDebugMode: ?debug=true', () => {
    window.location.search = '?debug=true';
    assert.equal(isDebugMode(), true);
    window.location.search = '?debug=1';
    assert.equal(isDebugMode(), false);
    window.location.search = '';
});

test('materialIconFor: Material names, SF Symbols of the iOS app, default icon', () => {
    const available = name => /^[a-z_]+$/.test(name);
    assert.equal(materialIconFor('shopping_cart', available), 'shopping_cart');
    assert.equal(materialIconFor('house', () => false), 'category');
    assert.equal(materialIconFor('house', name => name === 'home'), 'home');
    // Variants of an SF Symbol
    assert.equal(materialIconFor('car.fill', name => name === 'directions_car'), 'directions_car');
    assert.equal(materialIconFor('', available), 'category');
    assert.equal(materialIconFor('  ', available), 'category');
    assert.equal(materialIconFor('unknown.symbol', name => name !== 'unknown.symbol'), 'category');
});

test('cssColorFor: hex, SwiftUI names, CSS names', () => {
    assert.equal(cssColorFor('#9e9e9e'), '#9e9e9e');
    assert.equal(cssColorFor('Teal'), '#30b0c7');
    assert.equal(cssColorFor('tomato'), 'tomato');
    assert.equal(cssColorFor('notacolor'), null);
    assert.equal(cssColorFor('red; background: url(x)'), null);
    assert.equal(cssColorFor(''), null);
    assert.equal(cssColorFor(null), null);
});

test('badgeStyle: the color on a light tint of it, lightened by the dark theme', () => {
    assert.deepEqual(badgeStyle('#ff0000'), {
        // --category-ink: 100% (the color itself) in the light theme, 60% in the dark one
        color: 'color-mix(in srgb, #ff0000 var(--category-ink, 100%), white)',
        background: 'color-mix(in srgb, #ff0000 14%, transparent)'
    });
});

test('the colors and icons offered for the categories are understood by the portal', () => {
    for (const color of CATEGORY_COLORS) {
        assert.ok(cssColorFor(color), color);
    }
    assert.ok(CATEGORY_ICONS.length > 20);
    assert.equal(new Set(CATEGORY_ICONS).size, CATEGORY_ICONS.length);
});

test('swaggerFramed: Swagger UI shown, blocked by a proxy, or a frame being created', async () => {
    const { swaggerFramed } = await import('../../portal/utils/apiDocs.js');
    const page = (url, swagger) => ({ contentDocument: { URL: url, getElementById: id => (swagger && id === 'swagger-ui' ? {} : null) } });
    assert.equal(swaggerFramed(page('https://nas/swagger/', true)), true);
    // X-Frame-Options: DENY: an error page of another origin, unreadable
    assert.equal(swaggerFramed({ contentDocument: null }), false);
    assert.equal(swaggerFramed({ get contentDocument() { throw new Error('SecurityError'); } }), false);
    // Same origin, but not Swagger UI (an error page of the server)
    assert.equal(swaggerFramed(page('https://nas/swagger/', false)), false);
    // Empty page of the frame before its first navigation: no verdict yet
    assert.equal(swaggerFramed(page('about:blank', false)), null);
});
