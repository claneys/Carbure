// Contrast checks of the portal (WCAG 2.2 AA): the texts of the page, and the buttons at
// rest and on hover, computed in the browser from the colors actually rendered.
//
// Not checked: the colors chosen by the users (categories: inline style), the SVG charts,
// and the decorative illustrations of the empty states.

/** Minimum contrast of a text (WCAG 2.2 AA, 1.4.3); 3:1 for a large text */
const MIN_TEXT = 4.5;
const MIN_LARGE_TEXT = 3;

/**
 * Contrast of the text of an element in the page: its color against the background
 * actually under it (backgrounds of the ancestors composited, worst color of a
 * gradient, opacity of the element and its ancestors applied to the text).
 * Runs in the browser.
 * @param {Element} element
 * @returns {{ratio: number, fg: string, bg: string, large: boolean}|null} null without text
 */
function measure(element) {
    const parse = value => {
        const match = String(value).match(/rgba?\(([^)]+)\)/);
        if (!match) {
            return null;
        }
        const [r, g, b, a = 1] = match[1].split(/[ ,/]+/).filter(Boolean).map(Number);
        return { r, g, b, a };
    };
    const over = (top, bottom) => {
        const a = top.a + bottom.a * (1 - top.a);
        const mix = k => a === 0 ? 0 : (top[k] * top.a + bottom[k] * bottom.a * (1 - top.a)) / a;
        return { r: mix('r'), g: mix('g'), b: mix('b'), a };
    };
    const luminance = c => {
        const channel = v => {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
    };
    const ratio = (a, b) => {
        const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
        return (l1 + 0.05) / (l2 + 0.05);
    };
    const css = c => `rgb(${Math.round(c.r)}, ${Math.round(c.g)}, ${Math.round(c.b)})`;

    // Colors chosen by the users, charts, decorative illustrations
    if (element.closest('svg, [style*="color"], .empty-state > .material-icons')) {
        return null;
    }

    // Text of the element itself (not only of a child that has its own colors)
    const text = [...element.childNodes].filter(n => n.nodeType === Node.TEXT_NODE).map(n => n.textContent).join('').trim();
    if (!text) {
        return null;
    }

    // Background layers from the element up to an opaque one; a gradient gives several
    // candidate colors (the worst one is kept)
    let candidates = [[]];
    let opacity = 1;
    for (let node = element; node && node.nodeType === Node.ELEMENT_NODE; node = node.parentElement) {
        const style = getComputedStyle(node);
        opacity *= Number(style.opacity);
        const stops = (style.backgroundImage.match(/rgba?\([^)]+\)/g) || []).map(parse);
        const color = parse(style.backgroundColor);
        const layers = stops.length ? stops.map(stop => color && color.a ? over(stop, color) : stop) : [color];
        candidates = candidates.flatMap(stack => layers.map(layer => [...stack, layer]));
        if (layers.every(layer => layer && layer.a >= 1)) {
            break;
        }
    }

    const fgStyle = parse(getComputedStyle(element).color);
    let worst = null;
    for (const stack of candidates) {
        let bg = { r: 255, g: 255, b: 255, a: 1 };
        for (const layer of stack.filter(Boolean).reverse()) {
            bg = over(layer, bg);
        }
        const fg = over({ ...fgStyle, a: fgStyle.a * opacity }, bg);
        const value = ratio(fg, bg);
        if (!worst || value < worst.ratio) {
            worst = { ratio: value, fg: css(fg), bg: css(bg) };
        }
    }
    const style = getComputedStyle(element);
    const size = parseFloat(style.fontSize);
    const bold = Number(style.fontWeight) >= 700;
    // An icon (ligature of the icon font) is a graphical object: 3:1 like a large text
    const icon = element.classList.contains('material-icons');
    return { ...worst, large: icon || size >= 24 || (bold && size >= 18.66), text: text.slice(0, 40) };
}

/**
 * Buttons of the page whose text is not readable enough, at rest and on hover.
 * @param {import('@playwright/test').Page} page
 * @param {string} where - Name of the screen (for the report)
 * @returns {Promise<Array<{where, state, selector, text, ratio, fg, bg}>>}
 */
async function contrastReport(page, where) {
    const failures = [];
    const buttons = page.locator('button:visible, a.btn:visible, [role="button"]:visible, [role="tab"]:visible, [role="menuitem"]:visible');
    const count = await buttons.count();
    // Buttons with the same classes look the same: the first one is checked
    const seen = new Set();
    for (let i = 0; i < count; i++) {
        const button = buttons.nth(i);
        if (await button.isDisabled().catch(() => true)) {
            continue;
        }
        const selector = await button.evaluate(el => el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''))
            .catch(() => null);
        if (selector === null || seen.has(selector)) {
            continue;
        }
        seen.add(selector);
        for (const state of ['rest', 'hover']) {
            if (state === 'hover') {
                const hovered = await button.hover({ timeout: 1000 }).then(() => true, () => false);
                if (!hovered) {
                    continue;
                }
            }
            // The button and the elements inside it that have their own text
            const measures = await button.evaluate((el, source) => {
                const fn = new Function(`return (${source})`)();
                return [el, ...el.querySelectorAll('*')].map(fn).filter(Boolean);
            }, measure.toString()).catch(() => []);
            for (const m of measures) {
                if (m.ratio < (m.large ? MIN_LARGE_TEXT : MIN_TEXT)) {
                    failures.push({ where, state, selector, ...m });
                }
            }
        }
    }
    await page.mouse.move(0, 0);
    return failures;
}

/**
 * Texts of the page (any visible element) that are not readable enough.
 * @param {import('@playwright/test').Page} page
 * @param {string} where - Name of the screen (for the report)
 * @returns {Promise<Array<{where, state, selector, text, ratio, fg, bg}>>}
 */
async function textReport(page, where) {
    const measures = await page.evaluate(source => {
        const fn = new Function(`return (${source})`)();
        const visible = el => {
            const rect = el.getBoundingClientRect();
            const style = getComputedStyle(el);
            return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && !el.closest('[aria-hidden="true"]:not(.material-icons)');
        };
        const name = el => el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : '');
        return [...document.querySelectorAll('body *')]
            .filter(el => !['SCRIPT', 'STYLE', 'OPTION'].includes(el.tagName) && visible(el) && !el.closest(':disabled'))
            .map(el => {
                const m = fn(el);
                return m && { ...m, selector: name(el) };
            })
            .filter(Boolean);
    }, measure.toString());
    return measures
        .filter(m => m.ratio < (m.large ? MIN_LARGE_TEXT : MIN_TEXT))
        .map(m => ({ where, state: 'text', ...m }));
}

/**
 * Every contrast problem of the screen: texts at rest, buttons at rest and on hover.
 * @param {import('@playwright/test').Page} page
 * @param {string} where - Name of the screen (for the report)
 * @returns {Promise<string[]>} One line per problem (empty: all readable)
 */
async function contrastProblems(page, where) {
    // No transition while measuring: even with reduced motion, a transition starts at the
    // next frame, and a color read before it is the previous one (a button just enabled
    // would still have the colors of a disabled one)
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });
    const problems = [...await textReport(page, where), ...await contrastReport(page, where)];
    const lines = problems.map(p => `${p.where} [${p.state}] ${p.selector} "${p.text}": ${p.ratio.toFixed(2)}:1 (${p.fg} on ${p.bg})`);
    return [...new Set(lines)];
}

module.exports = { contrastProblems };
