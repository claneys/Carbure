/**
 * Utility Functions Module
 * 
 * Provides formatting and helper functions used across the application.
 * 
 * @module utils
 */

/**
 * Formats a monetary amount according to locale
 * @param {number} amount - Amount to format
 * @param {string} locale - Locale code (e.g., 'fr-FR', 'en-US')
 * @returns {string} Formatted amount
 */
export function formatAmount(amount, locale = 'fr-FR') {
    return new Intl.NumberFormat(locale, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

/**
 * Symbol of a currency in a locale (€, $, £...), its code when Intl does not know it
 * @param {string} currency - ISO 4217 code (e.g., 'EUR', 'USD')
 * @param {string} locale - Locale code (e.g., 'fr-FR', 'en-US')
 * @returns {string} The symbol
 */
export function currencySymbol(currency, locale = 'fr-FR') {
    try {
        const part = new Intl.NumberFormat(locale, { style: 'currency', currency, currencyDisplay: 'narrowSymbol' })
            .formatToParts(0).find(p => p.type === 'currency');
        return part ? part.value : currency;
    } catch (e) {
        return currency;
    }
}

/**
 * Formats a date string according to locale
 * @param {string} dateString - ISO date string
 * @param {string} locale - Locale code (e.g., 'fr-FR', 'en-US')
 * @returns {string} Formatted date
 */
export function formatDate(dateString, locale = 'fr-FR') {
    // MySQL datetimes ("2026-10-03 15:00:00") are not ISO: Safari rejects the space
    const date = new Date(String(dateString).replace(' ', 'T'));
    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'long',
        year: 'numeric'
    }).format(date);
}

/**
 * Gets Material icon name for transaction type
 * @param {number} type - Transaction type ID
 * @returns {string} Material icon name
 */
export function getTransactionIcon(type) {
    const icons = {
        1: 'compare_arrows',
        2: 'schedule',
        3: 'receipt',
        4: 'add_circle',
        5: 'replay',
        6: 'local_atm',
        7: 'credit_card',
        8: 'shopping_cart',
        9: 'percent',
        12: 'pending'
    };
    return icons[type] || 'payment';
}

/**
 * Gets Material icon name for insight type
 * @param {string} name - Insight name
 * @returns {string} Material icon name
 */
export function getInsightIcon(name) {
    const icons = {
        'CREDIT': 'trending_up',
        'DEBIT': 'trending_down',
        'BUDGET-PLANIFIE': 'event_note',
        'HORS-BUDGET': 'error_outline',
        'EPARGNE': 'savings'
    };
    return icons[name] || 'insights';
}

/**
 * Checks if debug mode is enabled via query string
 * @returns {boolean} True if debug=true in URL
 */
export function isDebugMode() {
    try {
        const params = new URLSearchParams(window.location.search);
        const debugParam = params.get('debug');
        return String(debugParam).toLowerCase() === 'true';
    } catch (e) {
        return false;
    }
}
