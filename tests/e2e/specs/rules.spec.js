// Rules tab (administrators): categorization rules by keyword
const { test, expect, tr, openTab, pickCategory, currentMonth } = require('../fixtures');

/** Button of a rule, by its keyword */
const rule = (page, keyword) => page.getByRole('button', { name: `${tr('editRule')} ${keyword}` });

test('lists the rules grouped by category, with a filter', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));

    await expect(page.getByRole('heading', { name: tr('rulesCount', { count: 4 }) })).toBeVisible();
    await expect(page.locator('.rules-group')).toHaveCount(4);
    // LOYER notifies the household: bell and light red background
    await expect(rule(page, 'LOYER').locator('.rule-notify')).toBeVisible();
    await expect(rule(page, 'LOYER')).toHaveClass(/rule-chip-notify/);

    await page.getByRole('searchbox', { name: tr('ruleFilterPlaceholder') }).fill('super');
    await expect(page.locator('.rule-chip')).toHaveCount(1);
    await expect(rule(page, 'SUPERMARCHE')).toBeVisible();
});

test('adds a rule, applied at once to the existing transactions', async ({ adminPage: page, api }) => {
    await openTab(page, tr('tabRules'));
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();

    const modal = page.getByRole('dialog', { name: tr('addRuleTitle') });
    await modal.getByLabel(new RegExp(tr('ruleKeyword'))).fill('BOULANGERIE');
    await pickCategory(modal, 'Alimentation');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('ruleSavedApplied', { count: 1 }));
    await expect(rule(page, 'BOULANGERIE')).toBeVisible();

    const { month, year } = currentMonth();
    const transactions = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    expect(Number(transactions.find(t => t.label === 'CB BOULANGERIE PAUL').category)).toBe(1);
});

test('refuses a rule without keyword', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();

    await page.getByRole('dialog').getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('ruleRequired'));
    await expect(page.getByRole('dialog')).toBeVisible();
});

test('modifies a rule, showing the transactions it matches', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await rule(page, 'LOYER').click();

    const modal = page.getByRole('dialog', { name: tr('editRule') });
    await expect(modal.locator('.rule-count')).toContainText(tr('ruleCount', { count: 3, categorized: 3 }));
    await expect(modal.getByLabel(tr('ruleNotifyLabel'))).toBeChecked();
    // Push or email, as the user chose: the option does not say push only
    await expect(modal.getByLabel('Envoyer une notification', { exact: true })).toBeVisible();

    await modal.getByLabel(tr('ruleNotifyLabel')).uncheck();
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal).toHaveCount(0);
    await expect(rule(page, 'LOYER').locator('.rule-notify')).toHaveCount(0);
    await expect(rule(page, 'LOYER')).not.toHaveClass(/rule-chip-notify/);
});

test('deletes a rule', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await rule(page, 'SALAIRE').click();

    await page.getByRole('dialog').getByRole('button', { name: tr('deleteRule') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('ruleDeleted'));
    await expect(rule(page, 'SALAIRE')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: tr('rulesCount', { count: 3 }) })).toBeVisible();
});

test('applies all the rules to the whole history', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await page.getByRole('button', { name: tr('applyRulesHelp') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('rulesApplied', { count: 0 }));
});
