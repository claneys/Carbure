// Accessibility checks of a screen with axe-core (npm devDependency, never loaded from a
// URL): the WCAG 2.2 A/AA rules, and the layout checks of WCAG 1.4.4, 1.4.10 and 1.4.12
// (reflow, zoom, text spacing) measured in the browser.
const { AxeBuilder } = require('@axe-core/playwright');

/** Rules of axe run on every screen: WCAG 2.0, 2.1 and 2.2, levels A and AA */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/**
 * Violations of the WCAG rules on the screen, one readable line per element
 * @param {import('@playwright/test').Page} page
 * @param {string} where - Name of the screen (for the report)
 * @returns {Promise<string[]>} "screen: rule (impact) selector: message"
 */
async function axeViolations(page, where) {
    const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
    return results.violations.flatMap(v => v.nodes.map(n =>
        `${where}: ${v.id} (${v.impact}) ${n.target.join(' ')}: ${n.failureSummary.split('\n').slice(1).join(' ').trim()}`));
}

/**
 * Whether the document scrolls horizontally (WCAG 1.4.10: the content reflows at
 * 320 CSS px; a table or a chart may scroll inside its own container)
 * @param {import('@playwright/test').Page} page
 * @param {string} where - Name of the screen (for the report)
 * @returns {Promise<string[]>} The elements wider than the viewport, if the page scrolls
 */
async function reflowProblems(page, where) {
    return page.evaluate(where => {
        const root = document.documentElement;
        if (root.scrollWidth <= root.clientWidth + 1) {
            return [];
        }
        // The widest elements that stick out of the viewport, outside a scrolling container
        const name = el => el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : '');
        const scrolls = el => {
            for (let p = el.parentElement; p; p = p.parentElement) {
                const o = getComputedStyle(p).overflowX;
                if (o === 'auto' || o === 'scroll' || o === 'hidden') {
                    return true;
                }
            }
            return false;
        };
        const out = [...document.querySelectorAll('body *')]
            .filter(el => el.getBoundingClientRect().right > root.clientWidth + 1 && !scrolls(el))
            .map(el => `${where}: ${name(el)} ends at ${Math.round(el.getBoundingClientRect().right)}px`);
        return [`${where}: the page scrolls horizontally (${root.scrollWidth}px for ${root.clientWidth}px)`, ...out.slice(0, 5)];
    }, where);
}

/**
 * Texts cut by their box: an element whose content is wider or taller than its box while
 * its overflow is hidden (WCAG 1.4.4 and 1.4.12). Elements truncated on purpose (ellipsis,
 * with their full text in a title) are not counted.
 * @param {import('@playwright/test').Page} page
 * @param {string} where - Name of the screen (for the report)
 * @returns {Promise<string[]>}
 */
async function clippedTexts(page, where) {
    return page.evaluate(where => {
        const name = el => el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : '');
        // Texts hidden on purpose for the screen readers (.visually-hidden: 1 px box) are not cut
        const visible = el => {
            const r = el.getBoundingClientRect();
            return r.width > 1 && r.height > 1 && getComputedStyle(el).visibility !== 'hidden';
        };
        return [...document.querySelectorAll('button, a, label, [role="tab"], [role="menuitem"], h1, h2, h3, th, .tab-button')]
            .filter(visible)
            .filter(el => {
                const style = getComputedStyle(el);
                const hidden = ['hidden', 'clip'].includes(style.overflowX) || ['hidden', 'clip'].includes(style.overflowY);
                if (!hidden || style.textOverflow === 'ellipsis' || el.title) {
                    return false;
                }
                return el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1;
            })
            .map(el => `${where}: ${name(el)} "${el.textContent.trim().slice(0, 40)}" is cut`);
    }, where);
}

/** Text spacing of WCAG 1.4.12 (the "text spacing bookmarklet") */
const TEXT_SPACING = `* { line-height: 1.5 !important; letter-spacing: 0.12em !important; word-spacing: 0.16em !important; }
p { margin-bottom: 2em !important; }`;

module.exports = { axeViolations, reflowProblems, clippedTexts, TEXT_SPACING, WCAG_TAGS };
