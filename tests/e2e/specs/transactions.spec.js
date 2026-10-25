// Transactions tab: month view, totals, search, check, categorization, detail modal
const { test, expect, tr, openTab, pickCategory, euros, currentMonth } = require('../fixtures');
const path = require('path');

/** Row of a transaction, by its label */
const row = (page, label) => page.locator('.transaction-item').filter({ hasText: label });

test('lists the transactions of the month with their totals', async ({ adminPage: page }) => {
    await expect(page.locator('.transaction-item')).toHaveCount(6);
    await expect(page.locator('.transaction-count')).toHaveText(tr('transactionCount', { count: 6 }));

    const stats = page.locator('.stat-card');
    await expect(stats.filter({ hasText: tr('incomeLabel') }).locator('.stat-value')).toHaveText(euros(3200));
    await expect(stats.filter({ hasText: tr('expenseLabel') }).locator('.stat-value')).toHaveText(euros(1584.2));
    await expect(stats.filter({ hasText: tr('balanceLabel') }).locator('.stat-value')).toHaveText(euros(1615.8));

    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.transaction-amount')).toHaveText(euros(-82.4));
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.category-chip')).toHaveText('Supermarché');
});

test('shows the icon and the color of the category of each transaction', async ({ adminPage: page }) => {
    // Icon of the category itself (not of its parent), as on the categories page
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.transaction-icon .material-icons')).toHaveText('shopping_cart');
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC').locator('.transaction-icon .material-icons')).toHaveText('local_dining');
    await expect(row(page, 'PRLV LOYER AGENCE').locator('.transaction-icon .material-icons')).toHaveText('home');
    // Uncategorized: the icon of the type of operation (card)
    await expect(row(page, 'CB BOULANGERIE PAUL').locator('.transaction-icon')).toHaveClass(/type-7/);

    await openTab(page, tr('tabCategories'));
    const category = page.locator('.category-row').filter({ has: page.locator('.category-row-name', { hasText: /^Restaurant$/ }) });
    await expect(category.locator('.material-icons').first()).toHaveText('local_dining');
});

test('shows another month', async ({ adminPage: page }) => {
    const previous = currentMonth(1);
    await page.locator('#year').selectOption(String(previous.year));
    await page.locator('#month').selectOption(String(previous.month));

    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(row(page, 'CB SUPERMARCHE CARREFOUR')).toBeVisible();
});

test('offers only the months that have transactions', async ({ adminPage: page }) => {
    // The data set: the current month and the two previous ones
    const periods = [0, 1, 2].map(ago => currentMonth(ago));
    const years = [...new Set(periods.map(p => String(p.year)))];
    await expect(page.locator('#year option')).toHaveText(years);

    const { year } = currentMonth();
    const months = periods.filter(p => p.year === year).map(p => p.month).sort((a, b) => a - b);
    await expect(page.locator('#month option')).toHaveCount(months.length);
    await expect(page.locator('#month option').first()).toHaveAttribute('value', String(months[0]));
});

test('searches the transactions of every month by label', async ({ adminPage: page }) => {
    await page.getByRole('searchbox', { name: tr('searchPlaceholder') }).fill('LOYER');

    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(page.locator('.search-total')).toContainText(tr('searchTotal', { count: 3 }));
    await expect(page.locator('.search-total strong')).toHaveText(euros(-2850));

    await page.getByRole('button', { name: tr('clearSearch') }).click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);

    await page.getByRole('searchbox', { name: tr('searchPlaceholder') }).fill('INTROUVABLE');
    await expect(page.locator('.transactions-hint')).toHaveText(tr('noSearchResults'));
});

test('filters the transactions not checked yet', async ({ adminPage: page }) => {
    const toggle = page.locator('.toggle-unchecked');
    await expect(page.locator('.toggle-unchecked .toggle-label')).toHaveText(tr('uncheckedCount', { count: 3 }));

    await toggle.click();
    await expect(toggle.getByRole('switch')).toBeChecked();
    await expect(page.locator('.transaction-item')).toHaveCount(3);

    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);
});

test('checks and unchecks a transaction', async ({ adminPage: page, api }) => {
    const transaction = row(page, 'CB SUPERMARCHE CASINO');
    await transaction.getByRole('button', { name: tr('checkTransaction') }).click();
    await expect(transaction.getByRole('button', { name: tr('uncheckTransaction') })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.toggle-unchecked .toggle-label')).toHaveText(tr('uncheckedCount', { count: 2 }));

    const { month, year } = currentMonth();
    const saved = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    expect(Number(saved.find(t => t.label === 'CB SUPERMARCHE CASINO').pointed)).toBe(1);

    await page.reload();
    await expect(row(page, 'CB SUPERMARCHE CASINO').getByRole('button', { name: tr('uncheckTransaction') })).toBeVisible();
});

test('categorizes a transaction and proposes a rule', async ({ adminPage: page }) => {
    const transaction = row(page, 'CB BOULANGERIE PAUL');
    await pickCategory(transaction, 'Alimentation');

    await expect(transaction.locator('.category-chip')).toHaveText('Alimentation');
    const suggestion = page.locator('.rule-suggestion');
    await expect(suggestion).toContainText(tr('ruleSuggestion', { label: 'CB BOULANGERIE PAUL', category: 'Alimentation' }));

    // The rule modal opens on the rules tab, filled in
    await suggestion.getByRole('button', { name: tr('createRule') }).click();
    await expect(page.locator('.tabs .tab-button.active')).toHaveAccessibleName(tr('tabRules'));
    await expect(page.locator('#rule-keyword')).toHaveValue('CB BOULANGERIE PAUL');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.getByRole('button', { name: `${tr('editRule')} CB BOULANGERIE PAUL` })).toBeVisible();
});

test('a user categorizes without being offered a rule', async ({ userPage: page }) => {
    const transaction = row(page, 'CB BOULANGERIE PAUL');
    await pickCategory(transaction, 'Alimentation');

    await expect(transaction.locator('.category-chip')).toHaveText('Alimentation');
    await expect(page.locator('.rule-suggestion')).toHaveCount(0);
});

test('the category list of a row closes when the page scrolls, not on a late scroll event', async ({ userPage: page }) => {
    await page.setViewportSize({ width: 1280, height: 500 });
    const transaction = row(page, 'CB BOULANGERIE PAUL');
    await transaction.locator('.category-picker-button').click();
    const search = transaction.locator('.category-picker-search input');
    await expect(search).toBeVisible();

    // A scroll event that did not move the button (end of the scroll bringing it into view)
    await page.evaluate(() => window.dispatchEvent(new Event('scroll')));
    await expect(search).toBeVisible();

    // Towards where the page can scroll: the click may have scrolled it to the bottom
    await page.evaluate(() => window.scrollBy(0, window.scrollY > 0 ? -100 : 100));
    await expect(search).toHaveCount(0);
});

test('changes the category and the checked state in the detail modal', async ({ adminPage: page, api }) => {
    await row(page, 'CB RESTAURANT LE PETIT ZINC').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();

    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(modal.locator('.transaction-detail-amount')).toHaveText(euros(-45));
    // Bank at the origin of the transaction
    await expect(modal.locator('.transaction-detail-bank')).toHaveText('BNP ···0001');
    await pickCategory(modal, 'Supermarché');
    await modal.getByLabel(tr('transactionChecked')).check();
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(modal).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC').locator('.category-chip')).toHaveText('Supermarché');

    const { month, year } = currentMonth();
    const saved = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json())
        .find(t => t.label === 'CB RESTAURANT LE PETIT ZINC');
    expect(Number(saved.category)).toBe(2);
    expect(Number(saved.pointed)).toBe(1);
});

test('deletes a transaction from its detail', async ({ adminPage: page, api }) => {
    await row(page, 'CB RESTAURANT LE PETIT ZINC').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });

    // The confirmation is accepted (fixtures)
    await modal.getByRole('button', { name: tr('deleteTransaction') }).click();

    await expect(modal).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('transactionDeleted'));
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC')).toHaveCount(0);
    await expect(page.locator('.transaction-item')).toHaveCount(5);

    // Deleted on the server
    const { month, year } = currentMonth();
    const labels = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json()).map(t => t.label);
    expect(labels).not.toContain('CB RESTAURANT LE PETIT ZINC');
});

test('searches the transactions similar to the one of the detail', async ({ adminPage: page }) => {
    await row(page, 'PRLV LOYER AGENCE').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });
    await modal.getByRole('button', { name: tr('searchSimilarTransactions') }).click();

    // The detail closes, the label search runs with the label without "PRLV"
    await expect(modal).toHaveCount(0);
    const search = page.getByRole('searchbox', { name: tr('searchPlaceholder') });
    await expect(search).toHaveValue('LOYER AGENCE');
    await expect(search).toBeFocused();
    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(page.locator('.search-total')).toContainText(tr('searchTotal', { count: 3 }));

    // Cleared like a typed search
    await page.getByRole('button', { name: tr('clearSearch') }).click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);
});

test('keeps the search of the similar transactions compact, down to 320 px', async ({ adminPage: page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await row(page, 'PRLV LOYER AGENCE').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });
    const similar = modal.getByRole('button', { name: tr('searchSimilarTransactions') });
    const body = modal.locator('.modal-body');

    for (const width of [1280, 320]) {
        await page.setViewportSize({ width, height: 800 });
        await expect(similar).toBeVisible();

        // A small button (not a full-size action), still a target of 24 x 24 px at least
        const box = await similar.boundingBox();
        expect(box.height).toBeGreaterThanOrEqual(24);
        expect(box.height).toBeLessThanOrEqual(32);
        expect(box.width).toBeGreaterThanOrEqual(24);
        // Inside the modal, without a horizontal scroll
        const area = await body.boundingBox();
        expect(box.x + box.width).toBeLessThanOrEqual(area.x + area.width);
        expect(await body.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);

        // Reached with the keyboard, its focus visible
        await modal.getByRole('button', { name: tr('close') }).focus();
        await page.keyboard.press('Tab');
        await expect(similar).toBeFocused();
        await expect(similar).toHaveCSS('outline-style', 'solid');
    }
});

test('closes the detail modal with Escape', async ({ adminPage: page }) => {
    await row(page, 'PRLV LOYER AGENCE').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('shows the recurring transactions of the month', async ({ adminPage: page }) => {
    // Salary and rent: in each of the 3 months of the data set
    const toggle = page.locator('.toggle-recurring');
    await expect(toggle).toContainText(tr('recurringCount', { count: 2 }));
    await expect(row(page, 'PRLV LOYER AGENCE').locator('.recurring-badge')).toBeVisible();
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.recurring-badge')).toHaveCount(0);

    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(2);
    await expect(row(page, 'VIR SALAIRE ACME')).toBeVisible();
    await expect(row(page, 'PRLV LOYER AGENCE')).toBeVisible();

    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);
});

test('shows the recurring transactions of the month in a calendar', async ({ adminPage: page }) => {
    await page.getByRole('button', { name: tr('recurringCalendarButton') }).click();
    const modal = page.locator('.modal-recurring-calendar');
    await expect(modal).toBeVisible();

    // Salary and rent, both received this month, on the day of their transaction
    await expect(modal.locator('.recurring-calendar-total.received')).toContainText(tr('recurringCalendarReceived', { count: 2 }));
    await expect(modal.locator('.recurring-calendar-item.status-received')).toHaveCount(2);
    await expect(modal.locator('.recurring-calendar-today')).toHaveCount(1);

    // A received one opens its transaction
    await modal.getByRole('button', { name: /PRLV LOYER AGENCE/ }).click();
    await expect(modal).toHaveCount(0);
    await expect(page.getByRole('dialog', { name: tr('transactionDetail') })).toContainText('PRLV LOYER AGENCE');
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: tr('recurringCalendarButton') }).click();
    await expect(modal).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('imports a bank statement file', async ({ adminPage: page }) => {
    const day = d => {
        const date = new Date();
        date.setDate(d);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    };
    const csv = 'Date;Libellé;Montant\n'
        // Same transaction as the data set: already there
        + `${day(1)};VIR SALAIRE ACME;3 200,00\n`
        // Same amount as CB SUPERMARCHE CASINO, one day later: probable duplicate
        + `${day(3)};CARTE SUPERMARCHE CASINO;-82,40\n`
        + `${day(7)};CB FLEURISTE ROSE;-25,00\n`
        // Twice the same day: two transactions
        + `${day(7)};CB FLEURISTE ROSE;-25,00\n`;

    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    await modal.locator('input[type="file"]').setInputFiles({ name: 'releve.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });

    await expect(modal.locator('.import-count.status-new strong')).toHaveText('2');
    await expect(modal.locator('.import-count.status-duplicate strong')).toHaveText('1');
    await expect(modal.locator('.import-count.status-known strong')).toHaveText('1');
    // No bank writes this header: its columns were guessed
    await expect(modal.locator('.import-bank')).toHaveText(tr('importBankGeneric'));
    await expect(modal.locator('.import-row.status-duplicate')).toContainText('CB SUPERMARCHE CASINO');
    // Only the new transaction is checked
    await expect(modal.locator('.import-row.status-duplicate input')).not.toBeChecked();
    await expect(modal.locator('.import-row.status-known input')).toBeDisabled();

    await modal.getByRole('button', { name: tr('importConfirm', { count: 2 }) }).click();
    await expect(modal).toHaveCount(0);
    await expect(row(page, 'CB FLEURISTE ROSE')).toHaveCount(2);
    await expect(page.locator('.transaction-item')).toHaveCount(8);
});

test('shows the bank whose export was recognized in the import preview', async ({ adminPage: page }) => {
    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });

    // An export of BoursoBank: read with its profile
    await modal.locator('input[type="file"]').setInputFiles(path.join(__dirname, '../../fixtures/import/boursorama.csv'));
    await expect(modal.locator('.import-bank')).toHaveText(tr('importBankRecognized', { bank: 'Boursorama / BoursoBank' }));
    await expect(modal.locator('.import-row')).toHaveCount(1);
    await expect(modal.locator('.import-row')).toContainText('CARTE 06/01/26 SNCF');

    // An OFX file is read the same for every bank: no line about the bank
    await modal.getByRole('button', { name: tr('importOtherFile') }).click();
    await modal.locator('input[type="file"]').setInputFiles(path.join(__dirname, '../../fixtures/import/releve.ofx'));
    await expect(modal.locator('.import-count.status-new strong')).toHaveText('4');
    await expect(modal.locator('.import-bank')).toHaveCount(0);
});

test('imports only the transactions left checked', async ({ adminPage: page }) => {
    const day = d => {
        const date = new Date();
        date.setDate(d);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    };
    const csv = 'Date;Libellé;Montant\n'
        // Same amount as CB SUPERMARCHE CASINO, one day later: probable duplicate
        + `${day(3)};CARTE SUPERMARCHE CASINO;-82,40\n`
        + `${day(6)};CB LIBRAIRIE PAGE;-18,90\n`
        + `${day(7)};CB FLEURISTE ROSE;-25,00\n`;

    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    await modal.locator('input[type="file"]').setInputFiles({ name: 'releve.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    const checkbox = label => modal.getByRole('checkbox', { name: new RegExp(label) });
    const all = modal.getByRole('checkbox', { name: tr('importSelectAll') });

    // The two new transactions are checked: one is unchecked
    await expect(checkbox('CB LIBRAIRIE PAGE')).toBeChecked();
    await checkbox('CB LIBRAIRIE PAGE').uncheck();
    await expect(modal.getByText(tr('importSelectedCount', { count: 1 }))).toBeVisible();
    await expect(modal.getByRole('button', { name: tr('importConfirm', { count: 1 }) })).toBeEnabled();

    // Check all (the probable duplicate too), then uncheck all: nothing to import
    await all.check();
    await expect(modal.getByRole('button', { name: tr('importConfirm', { count: 3 }) })).toBeEnabled();
    await all.uncheck();
    await expect(modal.getByRole('button', { name: tr('importConfirm', { count: 0 }) })).toBeDisabled();

    // Only the florist is imported
    await checkbox('CB FLEURISTE ROSE').check();
    await modal.getByRole('button', { name: tr('importConfirm', { count: 1 }) }).click();
    await expect(modal).toHaveCount(0);
    await expect(row(page, 'CB FLEURISTE ROSE')).toHaveCount(1);
    await expect(row(page, 'CB LIBRAIRIE PAGE')).toHaveCount(0);
    await expect(row(page, 'CARTE SUPERMARCHE CASINO')).toHaveCount(0);
});

test('imports a statement file without any account to synchronize', async ({ adminPage: page }) => {
    // No followed account anymore
    await openTab(page, tr('tabAccounts'));
    for (const bankId of ['DEMO0000001@bnp', 'fail@bank']) {
        const item = page.locator('.account-item').filter({ has: page.locator('code', { hasText: bankId }) });
        await item.getByRole('button', { name: tr('deleteAccount') }).click();
        await expect(item).toHaveCount(0);
    }

    // Empty month (as on a new installation): the import is offered by the empty state
    await page.route('**/api/transaction?month=*', route => route.fulfill({ json: [] }));
    await page.reload();
    await expect(page.locator('.empty-state')).toContainText(tr('emptyImportHint'));
    await page.locator('.empty-state').getByRole('button', { name: tr('importButton') }).click();
    await page.unroute('**/api/transaction?month=*');

    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    const csv = 'Date;Libellé;Montant\n15/03/2020;CB LIBRAIRIE;-18,90\n20/03/2020;VIR REMBOURSEMENT;40,00\n';
    await modal.locator('input[type="file"]').setInputFiles({ name: 'releve.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    await expect(modal.locator('.import-count.status-new strong')).toHaveText('2');
    await modal.getByRole('button', { name: tr('importConfirm', { count: 2 }) }).click();

    // The portal shows the month of the imported transactions
    await expect(modal).toHaveCount(0);
    await expect(row(page, 'CB LIBRAIRIE')).toHaveCount(1);
    await expect(page.locator('.transaction-item')).toHaveCount(2);
    // Other files are imported from the toolbar of the list
    await expect(page.locator('.empty-state')).toHaveCount(0);
    await expect(page.getByRole('button', { name: tr('importButton') })).toBeVisible();
});

test('explains why a file cannot be imported', async ({ adminPage: page }) => {
    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    await modal.locator('input[type="file"]').setInputFiles({ name: 'notes.csv', mimeType: 'text/csv', buffer: Buffer.from('a;b\n1;2\n') });
    await expect(modal.getByRole('alert')).toContainText('Columns not found');
});
