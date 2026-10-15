/**
 * Category icons and colors
 *
 * The categories store SF Symbols icon names and SwiftUI color names (used by
 * the iOS app). SF Symbols cannot be used on the web: they are mapped here to
 * Material Icons, and SwiftUI colors to their Apple values.
 *
 * @module categoryIcons
 */

/** SF Symbols name (without the ".fill" variant) -> Material Icons name */
const SF_TO_MATERIAL = {
    // Categories of the household
    'questionmark.square': 'help_center',
    'house': 'home',
    'car': 'directions_car',
    'calendar': 'calendar_today',
    'theatermasks': 'theater_comedy',
    'figure.and.child.holdinghands': 'family_restroom',
    'tshirt': 'checkroom',
    'eurosign': 'euro',
    'cross.case': 'medical_services',
    'flag': 'flag',
    'phone': 'phone',
    'fireworks': 'celebration',
    'person.2.gobackward': 'group',
    'creditcard': 'credit_card',
    'fork.knife': 'restaurant',
    'airplane': 'flight',
    'cup.and.saucer': 'local_cafe',
    'cart': 'shopping_cart',
    'storefront': 'storefront',
    'mouth': 'face_retouching_natural',
    'lock.circle': 'lock',
    'drop': 'water_drop',
    'hand.raised': 'back_hand',
    'person.and.background.dotted': 'work',
    'checkmark.gobackward': 'task_alt',
    'person.line.dotted.person': 'volunteer_activism',
    'gift': 'card_giftcard',
    'eurosign.arrow.circlepath': 'currency_exchange',
    'hammer': 'construction',
    'tree': 'park',
    // Other common symbols
    'bag': 'shopping_bag',
    'basket': 'shopping_basket',
    'gamecontroller': 'sports_esports',
    'heart': 'favorite',
    'pills': 'medication',
    'stethoscope': 'medical_services',
    'graduationcap': 'school',
    'book': 'menu_book',
    'pawprint': 'pets',
    'fuelpump': 'local_gas_station',
    'bus': 'directions_bus',
    'tram': 'tram',
    'bicycle': 'pedal_bike',
    'wrench': 'build',
    'wrench.and.screwdriver': 'handyman',
    'bolt': 'bolt',
    'flame': 'local_fire_department',
    'wifi': 'wifi',
    'tv': 'tv',
    'music.note': 'music_note',
    'film': 'movie',
    'dollarsign': 'attach_money',
    'banknote': 'payments',
    'building.columns': 'account_balance',
    'building.2': 'apartment',
    'sportscourt': 'sports_tennis',
    'figure.walk': 'directions_walk',
    'figure.run': 'directions_run',
    'dumbbell': 'fitness_center',
    'scissors': 'content_cut',
    'shield': 'shield',
    'umbrella': 'umbrella',
    'envelope': 'mail',
    'iphone': 'smartphone',
    'desktopcomputer': 'computer',
    'laptopcomputer': 'laptop',
    'gearshape': 'settings',
    'star': 'star',
    'sun.max': 'wb_sunny',
    'leaf': 'eco',
    'percent': 'percent',
    'chart.line.uptrend.xyaxis': 'trending_up',
    'arrow.left.arrow.right': 'swap_horiz',
    'person': 'person',
    'person.2': 'group',
    'person.3': 'groups'
};

/** SwiftUI color names that are not CSS colors -> Apple system colors */
const SWIFTUI_COLORS = {
    mint: '#00c7be',
    teal: '#30b0c7',
    indigo: '#5856d6',
    darkgray: '#555555',
    lightgray: '#aaaaaa',
    gray: '#8e8e93',
    brown: '#a2845e',
    cyan: '#32ade6',
    pink: '#ff2d55',
    purple: '#af52de',
    orange: '#ff9500',
    yellow: '#ffcc00',
    green: '#34c759',
    blue: '#007aff',
    red: '#ff3b30',
    magenta: '#d63ea8'
};

/**
 * Material icon name for a stored category icon (Material name or SF Symbol)
 * @param {string} icon - Stored icon
 * @param {Function} isAvailable - Checks that a Material icon can be rendered
 * @returns {string} Material icon name ('category' when unknown)
 */
export function materialIconFor(icon, isAvailable) {
    const name = (icon || '').trim();
    if (!name) {
        return 'category';
    }
    if (isAvailable(name)) {
        return name;
    }
    const mapped = SF_TO_MATERIAL[name] || SF_TO_MATERIAL[name.replace(/\.(fill|circle|square)$/, '')];
    return mapped && isAvailable(mapped) ? mapped : 'category';
}

/**
 * CSS color for a stored category color (CSS color, hex or SwiftUI name)
 * @param {string} color - Stored color
 * @returns {string|null} CSS color, or null when empty/unknown
 */
export function cssColorFor(color) {
    const value = (color || '').trim();
    if (!value) {
        return null;
    }
    if (/^#[0-9a-fA-F]{3,8}$/.test(value)) {
        return value;
    }
    const swiftUi = SWIFTUI_COLORS[value.toLowerCase()];
    if (swiftUi) {
        return swiftUi;
    }
    return /^[a-zA-Z]+$/.test(value) && CSS.supports('color', value) ? value : null;
}

/** SF Symbols names offered when creating or editing a category (shown with their Material icon) */
export const CATEGORY_ICONS = Object.keys(SF_TO_MATERIAL);

/**
 * Style of an icon badge: the icon in the color, on a light tint of it
 * @param {string} color - CSS color
 * @returns {{color: string, background: string}}
 */
export function badgeStyle(color) {
    // --category-ink (style.css): the color itself in the light theme, lightened in the dark
    // theme so that the icon stays readable on its dark tint
    return {
        color: `color-mix(in srgb, ${color} var(--category-ink, 100%), white)`,
        background: `color-mix(in srgb, ${color} 14%, transparent)`
    };
}

/** Color names understood by the iOS app (SwiftUI) and the portal */
export const CATEGORY_COLORS = ['red', 'orange', 'yellow', 'green', 'mint', 'teal', 'cyan', 'blue',
    'indigo', 'purple', 'pink', 'magenta', 'brown', 'gray', 'darkGray'];
