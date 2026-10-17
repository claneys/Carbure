// Administration > Backup: export of the chosen datasets in a ZIP, import of a ZIP or of
// a dataset file that deletes and replaces their data, after a confirmation
const { test, expect, tr } = require('../fixtures');
const fs = require('fs');
const path = require('path');

/** Data set of server/seed.php */
const SEED = { categories: 8, rules: 4, budgets: 3, insights: 1, transactions: 11, deleted_transactions: 0 };

/**
 * Opens Administration > Backup
 * @param {import('@playwright/test').Page} page
 */
async function openBackup(page) {
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabBackup'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('backupExportTitle') })).toBeVisible();
}

/**
 * Names of the files of a ZIP (read with the reader of the portal)
 * @param {string} file - Path of the ZIP
 * @returns {Promise<string[]>}
 */
async function zipNames(file) {
    const { readZip } = await import(path.join(__dirname, '../../../portal/utils/zip.js'));
    return (await readZip(fs.readFileSync(file))).map(entry => entry.name);
}

/**
 * A dataset file of a backup
 * @param {string} dataset - Dataset
 * @param {Array} rows - Rows
 * @returns {{name: string, mimeType: string, buffer: Buffer}}
 */
function datasetFile(dataset, rows) {
    return {
        name: `${dataset}.json`,
        mimeType: 'application/json',
        buffer: Buffer.from(JSON.stringify({ format: 'carbure-backup', version: 1, dataset, rows })),
    };
}

/**
 * Number of rows of each dataset, asked to the API
 * @param {import('@playwright/test').APIRequestContext} api
 * @returns {Promise<Object>} {name: count}
 */
async function counts(api) {
    const response = await api.get('/api/backup');
    expect(response.ok()).toBeTruthy();
    return Object.fromEntries((await response.json()).map(dataset => [dataset.name, dataset.count]));
}

test('an administrator exports chosen datasets, then restores them from the ZIP', async ({ adminPage: page, api }) => {
    await openBackup(page);
    const rules = page.locator('.backup-dataset').filter({ has: page.locator('input[data-dataset="rules"]') });
    await expect(rules).toContainText(tr('backupDatasetRules'));
    await expect(rules).toContainText(tr('backupRows', { count: SEED.rules }));

    // Without the transactions
    await page.locator('input[data-dataset="transactions"]').uncheck();
    await page.locator('input[data-dataset="deleted_transactions"]').uncheck();
    const downloading = page.waitForEvent('download');
    await page.getByRole('button', { name: tr('backupExportButton'), exact: true }).click();
    const download = await downloading;
    expect(download.suggestedFilename()).toMatch(/^carbure-backup-\d{4}-\d{2}-\d{2}\.zip$/);
    const zip = await download.path();
    expect(await zipNames(zip)).toEqual(['manifest.json', 'categories.json', 'rules.json', 'budgets.json', 'insights.json']);
    await expect(page.locator('.toast')).toHaveText(tr('backupExportDone'));

    // Changed after the backup
    const rulesList = await (await api.get('/api/category/keyword')).json();
    expect((await api.delete('/api/category/keyword', { params: { id: rulesList[0].id } })).ok()).toBeTruthy();
    expect((await counts(api)).rules).toBe(SEED.rules - 1);

    // Import of the ZIP: its files listed with their rows, all selected
    await page.locator('#backup-file').setInputFiles(zip);
    const files = page.locator('fieldset.backup-datasets').filter({ hasText: tr('backupImportChoose') }).locator('.backup-dataset');
    await expect(files).toHaveCount(4);
    await expect(files.filter({ hasText: 'rules.json' })).toContainText(tr('backupRows', { count: SEED.rules }));
    // The insights are not imported
    await page.locator('input[data-file="insights.json"]').uncheck();

    await page.locator('.backup-import-button').click();
    const dialog = page.getByRole('dialog', { name: tr('backupImportConfirmTitle') });
    await expect(dialog).toContainText(tr('backupDatasetRules'));
    await expect(dialog).not.toContainText(tr('backupDatasetInsights'));
    await dialog.getByRole('button', { name: tr('backupImportButton') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('backupImportDone', { count: SEED.categories + SEED.rules + SEED.budgets }));
    await expect(dialog).toHaveCount(0);

    // Restored; the transactions kept
    expect(await counts(api)).toEqual(SEED);
    await expect(page.locator('#backup-file')).toBeAttached();
});

test('a dataset file replaces only its data, after the checks of the server', async ({ adminPage: page, api }) => {
    await openBackup(page);

    // A rule of a category that does not exist: refused, nothing changed
    await page.locator('#backup-file').setInputFiles(datasetFile('rules', [{ id: 1, keyword: 'BOULANGERIE', category: 99, notify: false }]));
    await expect(page.locator('input[data-file="rules.json"]')).toBeChecked();
    await page.locator('.backup-import-button').click();
    const dialog = page.getByRole('dialog', { name: tr('backupImportConfirmTitle') });
    await dialog.getByRole('button', { name: tr('backupImportButton') }).click();
    await expect(dialog.locator('.confirm-error')).toContainText('categories that do not exist: 99');
    await dialog.getByRole('button', { name: tr('cancel') }).click();
    expect((await counts(api)).rules).toBe(SEED.rules);

    // Another file: a valid one
    await page.getByRole('button', { name: tr('backupOtherFile') }).click();
    await page.locator('#backup-file').setInputFiles(datasetFile('rules', [{ id: 1, keyword: 'BOULANGERIE', category: 1, notify: true }]));
    await page.locator('.backup-import-button').click();
    await dialog.getByRole('button', { name: tr('backupImportButton') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('backupImportDone', { count: 1 }));
    expect(await counts(api)).toEqual({ ...SEED, rules: 1 });
    const rules = await (await api.get('/api/category/keyword')).json();
    expect(rules.map(rule => rule.keyword)).toEqual(['BOULANGERIE']);
});

test('a file that is not a backup cannot be imported', async ({ adminPage: page }) => {
    await openBackup(page);
    await page.locator('#backup-file').setInputFiles({ name: 'notes.json', mimeType: 'application/json', buffer: Buffer.from('{"hello": "world"}') });
    await expect(page.locator('.backup-dataset.invalid')).toContainText(tr('backupFileInvalid'));
    await expect(page.locator('input[data-file="notes.json"]')).toBeDisabled();
    await expect(page.locator('.backup-import-button')).toBeDisabled();

    // A backup of a later version of Carbure: not read
    await page.getByRole('button', { name: tr('backupOtherFile') }).click();
    const later = { ...datasetFile('rules', []), buffer: Buffer.from(JSON.stringify({ format: 'carbure-backup', version: 2, dataset: 'rules', rows: [] })) };
    await page.locator('#backup-file').setInputFiles(later);
    await expect(page.locator('.backup-dataset.invalid')).toContainText(tr('backupFileVersion'));
    await expect(page.locator('input[data-file="rules.json"]')).toBeDisabled();
    await expect(page.locator('.backup-import-button')).toBeDisabled();
});

test('a user has no backup', async ({ userPage: page }) => {
    await expect(page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: tr('tabBackup') })).toHaveCount(0);
});
