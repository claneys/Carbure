// Display preferences: the theme of the profile (system / light / dark), and the high
// contrast settings of the system (prefers-contrast, forced colors). The contrasts of
// every screen in the dark theme are measured by accessibility.spec.js (chromium-dark).
const { test, expect, tr, openTab } = require('../fixtures');
const { contrastProblems } = require('../a11y');

/**
 * Opens the profile page from the user menu
 * @param {import('@playwright/test').Page} page
 */
async function openProfile(page) {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await expect(page.getByRole('heading', { name: tr('myProfile') })).toBeVisible();
}

/** Theme displayed by the page: light or dark */
const theme = page => page.locator('html');

test('follows the light/dark preference of the system by default', async ({ userPage: page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    await expect(theme(page)).toHaveAttribute('data-theme-choice', 'system');

    // A change of the system preference applies at once
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(theme(page)).toHaveAttribute('data-theme', 'light');
});

test('a theme chosen in the profile wins over the system and is kept', async ({ userPage: page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await openProfile(page);
    await expect(page.getByLabel(tr('theme'))).toHaveValue('system');

    await page.getByLabel(tr('theme')).selectOption('dark');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    // Dark surfaces, light text
    const colors = await page.locator('body').evaluate(el => getComputedStyle(el).color);
    expect(colors).toBe('rgb(226, 232, 240)');

    // Saved on the server: applied again on another browser (nothing cached)
    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.reload();
    await expect(page.locator('.user-menu-name')).toHaveText('marie');
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    await openProfile(page);
    await expect(page.getByLabel(tr('theme'))).toHaveValue('dark');

    // "Light" wins over a dark system
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.getByLabel(tr('theme')).selectOption('light');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));
    await expect(theme(page)).toHaveAttribute('data-theme', 'light');
});

test('the "increase contrast" setting of the system darkens the secondary texts', async ({ userPage: page }) => {
    await page.emulateMedia({ contrast: 'more', reducedMotion: 'reduce' });
    const secondary = await page.locator('html').evaluate(el => getComputedStyle(el).getPropertyValue('--text-secondary').trim());
    expect(secondary).toBe('#334155');
    expect(await contrastProblems(page, tr('tabTransactions'))).toEqual([]);

    await openTab(page, tr('tabBudget'));
    await page.waitForLoadState('networkidle');
    expect(await contrastProblems(page, tr('tabBudget'))).toEqual([]);

    // The same in the dark theme
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    expect(await contrastProblems(page, `${tr('tabBudget')} dark`)).toEqual([]);
    await openTab(page, tr('tabTransactions'));
    await page.waitForLoadState('networkidle');
    expect(await contrastProblems(page, `${tr('tabTransactions')} dark`)).toEqual([]);
});

test('in forced colors, the controls keep visible borders and focus', async ({ adminPage: page }) => {
    await page.emulateMedia({ forcedColors: 'active' });
    await openTab(page, tr('tabAccounts'));

    // Buttons with a colored background only (no border otherwise), tabs, fields
    const bordered = [
        page.locator('.tab-button.active'),
        page.locator('.tab-button:not(.active)').first(),
        page.getByRole('button', { name: tr('connectBankTitle') }),
        page.getByRole('button', { name: tr('syncAllAccounts') }),
    ];
    for (const control of bordered) {
        const border = await control.evaluate(el => {
            const style = getComputedStyle(el);
            return { style: style.borderTopStyle, width: parseFloat(style.borderTopWidth) };
        });
        expect(border.style, await control.evaluate(el => el.outerHTML.slice(0, 80))).not.toBe('none');
        expect(border.width).toBeGreaterThanOrEqual(1);
    }

    // The keyboard focus is drawn
    await page.keyboard.press('Tab');
    const outline = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement);
        return { style: style.outlineStyle, width: parseFloat(style.outlineWidth) };
    });
    expect(outline.style).not.toBe('none');
    expect(outline.width).toBeGreaterThanOrEqual(2);
});
