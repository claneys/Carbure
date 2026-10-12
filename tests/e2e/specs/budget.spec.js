// Budget tab: money flow, budgets by category, sub-categories, editing a budget
const { test, expect, tr, openTab, euros, currentMonth } = require('../fixtures');

/**
 * Checks the amounts of a budget card: spent / budget
 * @param {import('@playwright/test').Locator} item - Card
 * @param {number} spent
 * @param {string} budget - Text of the budget part ("/ 300,00 €")
 */
async function expectAmounts(item, spent, budget) {
    await expect(item.locator('.budget-card-amounts .spent')).toHaveText(euros(spent));
    await expect(item.locator('.budget-card-amounts .of')).toHaveText(budget);
}

/** Card of a category on the budgets view */
const card = (page, name) => page.locator('.budget-card').filter({ has: page.locator('.budget-card-name', { hasText: new RegExp(`^${name}$`) }) });

/**
 * Opens the budgets view of the Budget tab
 * @param {import('@playwright/test').Page} page
 */
async function openBudgets(page) {
    await openTab(page, tr('tabBudget'));
    await page.getByRole('tab', { name: tr('budgetConfigTab') }).click();
    await expect(page.locator('.budget-card').first()).toBeVisible();
}

/**
 * Edits the budget of a category from its card
 * @param {import('@playwright/test').Page} page
 * @param {string} name - Category
 * @returns {Promise<import('@playwright/test').Locator>} The edit modal
 */
async function editBudget(page, name) {
    await card(page, name).getByRole('button', { name: tr('budgetEditTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('budgetEditTitle') });
    await expect(modal).toContainText(name);
    return modal;
}

/**
 * Budget of a category returned by the API for the current month
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {string} name - Category
 * @param {number} parent - Parent category (0: top level)
 */
async function apiBudget(api, name, parent = 0) {
    const { month, year } = currentMonth();
    const items = await (await api.get(`/api/budget?month=${month}&year=${year}&category=${parent}`)).json();
    return items.find(item => item.name === name);
}

test('shows the money flow of the month', async ({ adminPage: page }) => {
    await openTab(page, tr('tabBudget'));

    await expect(page.getByRole('heading', { name: tr('flowTitle') })).toBeVisible();
    await page.getByRole('button', { name: tr('flowShowTable') }).click();
    const table = page.locator('.flow-table');
    await expect(table.getByRole('row').filter({ hasText: 'Salaire' })).toContainText(euros(3200).replace(' €', ''));
    await expect(table.getByRole('row').filter({ hasText: 'Logement' })).toContainText(euros(950).replace(' €', ''));
    await expect(table.getByRole('row').filter({ hasText: 'Alimentation' })).toContainText(euros(127.4).replace(' €', ''));
});

test('a click on a category of the money flow shows its transactions', async ({ adminPage: page }) => {
    await openTab(page, tr('tabBudget'));

    const node = page.locator('.flow-node[role="button"]').filter({ hasText: 'Alimentation' });
    await node.click();
    await expect(node).toHaveAttribute('aria-pressed', 'true');

    // Supermarché and Restaurant are grouped under Alimentation
    const list = page.locator('.flow-transactions');
    await expect(list.getByRole('heading')).toContainText('Alimentation');
    await expect(list.locator('.search-total strong')).toContainText('127,40');

    // A second click hides them
    await node.click();
    await expect(list).toHaveCount(0);
});

test('shows the budgets of the month by category', async ({ adminPage: page }) => {
    await openBudgets(page);

    await expect(page.locator('.budget-section-expenses .budget-card')).toHaveCount(2);
    await expectAmounts(card(page, 'Logement'), 950, `/ ${euros(1000)}`);
    await expect(card(page, 'Logement').locator('.budget-card-percent')).toHaveText('95 %');
    await expect(page.locator('.budget-section-incomes')).toContainText('Salaire');
    await expect(page.locator('.budget-section-others')).toContainText('Épargne');

    // Summary of the budgeted expenses: 950 + 127.40 of 1000 + 400
    const summary = page.locator('.budget-summary');
    await expect(summary).toContainText(euros(1077.4));
    await expect(summary).toContainText(euros(1400));
});

test('the budget of a parent category is the sum of its sub-categories', async ({ adminPage: page }) => {
    await openBudgets(page);

    const alimentation = card(page, 'Alimentation');
    await expect(alimentation.locator('.of')).toHaveText(`/ Σ ${euros(400)}`);
    await expect(alimentation.locator('.of')).toHaveAttribute('title', tr('budgetSumOfChildren'));
    await expect(alimentation.locator('.budget-card-percent')).toHaveText('32 %');

    // Its sub-categories and its transactions
    await alimentation.click();
    await expect(page.locator('.budget-breadcrumb')).toContainText('Alimentation');
    await expectAmounts(card(page, 'Supermarché'), 82.4, `/ ${euros(300)}`);
    await expectAmounts(card(page, 'Restaurant'), 45, `/ ${euros(100)}`);
    const transactions = page.locator('.budget-transactions');
    await expect(transactions).toContainText(tr('categoryTransactions', { category: 'Alimentation' }));
    await expect(transactions.locator('.transaction-item')).toHaveCount(2);

    await page.getByRole('button', { name: tr('allCategories') }).click();
    await expect(card(page, 'Logement')).toBeVisible();
});

test('edits the budget of a sub-category', async ({ adminPage: page, api }) => {
    await openBudgets(page);
    await card(page, 'Alimentation').click();

    const modal = await editBudget(page, 'Supermarché');
    // A category without sub-categories has no "sum" option
    await expect(modal.getByRole('checkbox')).toHaveCount(0);
    await modal.getByLabel(tr('budgetEditLabel')).fill('350');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('budgetUpdatedToast'));
    // Still on the sub-categories, updated
    await expect(page.locator('.budget-breadcrumb')).toContainText('Alimentation');
    await expect(card(page, 'Supermarché').locator('.of')).toHaveText(`/ ${euros(350)}`);

    await page.getByRole('button', { name: tr('allCategories') }).click();
    await expect(card(page, 'Alimentation').locator('.of')).toHaveText(`/ Σ ${euros(450)}`);
    expect(Number((await apiBudget(api, 'Supermarché', 1)).budget)).toBe(350);
});

test('overrides the budget of a parent category, then goes back to the sum', async ({ adminPage: page, api }) => {
    await openBudgets(page);

    let modal = await editBudget(page, 'Alimentation');
    const useChildren = modal.getByRole('checkbox', { name: tr('budgetUseChildren', { amount: euros(400) }) });
    await expect(useChildren).toBeChecked();
    await expect(modal.getByLabel(tr('budgetOverrideLabel'))).toHaveCount(0);

    await useChildren.uncheck();
    await modal.getByLabel(tr('budgetOverrideLabel')).fill('600');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal).toHaveCount(0);
    await expect(card(page, 'Alimentation').locator('.of')).toHaveText(`/ ${euros(600)}`);
    expect(await apiBudget(api, 'Alimentation')).toMatchObject({ budget_mode: 'own', children_budget: 400 });

    modal = await editBudget(page, 'Alimentation');
    await expect(modal.getByLabel(tr('budgetOverrideLabel'))).toHaveValue('600');
    await modal.getByRole('checkbox').check();
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(card(page, 'Alimentation').locator('.of')).toHaveText(`/ Σ ${euros(400)}`);
    expect((await apiBudget(api, 'Alimentation')).budget_mode).toBe('children');
});

test('stays green up to 105 % of the budget', async ({ adminPage: page }) => {
    await openBudgets(page);
    const logement = card(page, 'Logement');
    await expect(logement).toHaveClass(/status-ok/);

    // 950 of 910: 104 %
    let modal = await editBudget(page, 'Logement');
    await modal.getByLabel(tr('budgetEditLabel')).fill('910');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(logement.locator('.budget-card-percent')).toHaveText('104 %');
    await expect(logement).toHaveClass(/status-ok/);

    // 950 of 900: 106 %
    modal = await editBudget(page, 'Logement');
    await modal.getByLabel(tr('budgetEditLabel')).fill('900');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(logement.locator('.budget-card-percent')).toHaveText('106 %');
    await expect(logement).toHaveClass(/status-over/);
    await expect(logement.locator('.budget-card-foot')).toContainText(tr('overspentAmount', { amount: euros(50) }));
});

test('refuses an invalid amount', async ({ adminPage: page }) => {
    await openBudgets(page);

    const modal = await editBudget(page, 'Logement');
    await modal.getByLabel(tr('budgetEditLabel')).fill('');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('budgetInvalidAmount'));
    await expect(modal).toBeVisible();
    await modal.getByRole('button', { name: tr('cancel') }).click();
    await expect(modal).toHaveCount(0);
});

test('copies the budgets of the previous month, completing or overwriting', async ({ adminPage: page, api }) => {
    const prev = currentMonth(1);
    const prevName = tr(`months.${prev.month}`).toLowerCase();
    const copyButton = () => page.getByRole('button', { name: tr('budgetCopyButton', { month: prevName }), exact: true });

    // The previous month differs from the current one (Supermarché 300, Restaurant 100),
    // and Salaire has no budget yet
    for (const [category, amount] of [[2, 321], [3, 123], [5, 2000]]) {
        const response = await api.post('/api/budget', { data: { category, amount, month: prev.month, year: prev.year } });
        expect(response.ok()).toBeTruthy();
    }

    await openBudgets(page);
    await copyButton().click();
    let modal = page.getByRole('dialog', { name: tr('budgetCopyTitle') });
    await expect(modal).toContainText(tr('budgetCopyHelp', { month: prevName }));
    // Completing only, by default: only Salaire is copied
    await expect(modal.getByRole('radio', { name: tr('budgetCopyMissing') })).toBeChecked();
    await modal.getByRole('button', { name: tr('budgetCopyConfirm') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('budgetCopiedToast', { count: 1 }));
    await expect(page.locator('.budget-section-incomes')).toContainText('Salaire');
    expect(Number((await apiBudget(api, 'Supermarché', 1)).budget)).toBe(300);

    // Overwriting: the budgets of the previous month replace the current ones
    await copyButton().click();
    modal = page.getByRole('dialog', { name: tr('budgetCopyTitle') });
    await modal.getByRole('radio', { name: tr('budgetCopyOverwrite') }).check();
    await modal.getByRole('button', { name: tr('budgetCopyConfirm') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('budgetCopiedToast', { count: 3 }));
    await card(page, 'Alimentation').click();
    await expectAmounts(card(page, 'Supermarché'), 82.4, `/ ${euros(321)}`);
    expect(Number((await apiBudget(api, 'Supermarché', 1)).budget)).toBe(321);
});
