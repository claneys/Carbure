// Backup of Administration > Backup: ZIP written and read in the browser, files of a
// backup, import list
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createZip, readZip, crc32, isZip, ZIP_MAX_ENTRY_SIZE } from '../../portal/utils/zip.js';
import { BACKUP_VERSION, backupFiles, backupFileName, parseBackupJson, readBackupFile, backupImportDatasets, createBackupModule } from '../../portal/modules/backupModule.js';
import { createApiService } from '../../portal/services/apiService.js';

const text = bytes => new TextDecoder().decode(bytes);

/** ZIP made by Python's zipfile: a folder, a deflated dataset file, a stored text file */
const PYTHON_ZIP = 'UEsDBBQAAAAIAHa6UF0AAAAAAgAAAAAAAAAHAAAAYmFja3VwLwMAUEsDBBQAAAAIAHa6UF28We67ZwAAAHcAAAARAAAAYmFja3VwL3J1bGVzLmpzb24dyjEOgzAMRuG7/HM6ILbMpZdAHUxwUATFyE6KEOLupN2e9L0TUfRDGR6BdCjKj4HCXDY4fFktyQrfOIyUyfi3aVnYqqrsBt+fSOP/mPnYRWuje76qB8o8iR7wrcMqOcWakRbj633dUEsDBBQAAAAAAHa6UF3CQSQ1AwAAAAMAAAAJAAAAbm90ZXMudHh0YWJjUEsBAhQDFAAAAAgAdrpQXQAAAAACAAAAAAAAAAcAAAAAAAAAAAAQAP1BAAAAAGJhY2t1cC9QSwECFAMUAAAACAB2ulBdvFnuu2cAAAB3AAAAEQAAAAAAAAAAAAAAgAEnAAAAYmFja3VwL3J1bGVzLmpzb25QSwECFAMUAAAAAAB2ulBdwkEkNQMAAAADAAAACQAAAAAAAAAAAAAAgAG9AAAAbm90ZXMudHh0UEsFBgAAAAADAAMAqwAAAOcAAAAAAA==';

const BACKUP = {
    format: 'carbure-backup', version: 1, created_at: '2026-10-16T10:00:00+02:00', carbure: 'dev', schema: '2026-10-22_budget_alert',
    counts: { categories: 1, rules: 2 },
    datasets: {
        categories: [{ id: 0, name: 'Non catégorisé', parent_category: 0, type: 'HORS-BUDGET', icon: 'help', color: '#9e9e9e' }],
        rules: [{ id: 1, keyword: 'EDF', category: 3, notify: false }, { id: 2, keyword: 'SNCF', category: 4, notify: true }]
    }
};

test('crc32 of known values', () => {
    assert.equal(crc32(new TextEncoder().encode('123456789')), 0xCBF43926);
    assert.equal(crc32(new Uint8Array()), 0);
});

test('a ZIP written is read back, names in UTF-8', async () => {
    const files = [{ name: 'manifest.json', data: '{"a":1}' }, { name: 'catégories.json', data: 'x'.repeat(10000) }, { name: 'empty.json', data: '' }];
    const zip = await createZip(files, new Date(2026, 9, 16, 12, 30, 10));
    assert.ok(isZip(zip));
    // Compressed (deflate): much smaller than the content
    assert.ok(zip.length < 1000);

    const read = await readZip(zip);
    assert.deepEqual(read.map(file => [file.name, text(file.data)]), files.map(file => [file.name, file.data]));
});

test('reads a ZIP of another tool: folder skipped, deflate and stored entries', async () => {
    const read = await readZip(Buffer.from(PYTHON_ZIP, 'base64'));
    assert.deepEqual(read.map(file => file.name), ['backup/rules.json', 'notes.txt']);
    assert.equal(JSON.parse(text(read[0].data)).dataset, 'rules');
    assert.equal(text(read[1].data), 'abc');
});

test('a file that is not a ZIP is refused', async () => {
    assert.equal(isZip(new TextEncoder().encode('{"format":"carbure-backup"}')), false);
    await assert.rejects(readZip(new TextEncoder().encode('not a zip at all, really not')), { message: 'Not a ZIP file' });
});

test('backupFiles: the manifest and one file per dataset', () => {
    const files = backupFiles(BACKUP);
    assert.deepEqual(files.map(file => file.name), ['manifest.json', 'categories.json', 'rules.json']);
    const manifest = JSON.parse(files[0].data);
    assert.equal(manifest.format, 'carbure-backup');
    assert.equal(manifest.schema, '2026-10-22_budget_alert');
    assert.deepEqual(manifest.files['rules.json'], { dataset: 'rules', count: 2 });
    assert.deepEqual(JSON.parse(files[2].data), { format: 'carbure-backup', version: 1, dataset: 'rules', rows: BACKUP.datasets.rules });
    assert.equal(backupFileName(new Date(2026, 0, 5)), 'carbure-backup-2026-01-05.zip');
});

test('parseBackupJson: dataset file, manifest, whole export, unknown files', () => {
    const [, categories] = backupFiles(BACKUP);
    assert.deepEqual(parseBackupJson('categories.json', categories.data).entries,
        [{ file: 'categories.json', dataset: 'categories', rows: BACKUP.datasets.categories, count: 1, error: null }]);

    const manifest = parseBackupJson('manifest.json', backupFiles(BACKUP)[0].data);
    assert.deepEqual(manifest.entries, []);
    assert.equal(manifest.manifest.carbure, 'dev');

    const whole = parseBackupJson('export.json', JSON.stringify(BACKUP));
    assert.deepEqual(whole.entries.map(entry => [entry.dataset, entry.count]), [['categories', 1], ['rules', 2]]);

    assert.equal(parseBackupJson('x.json', '{oops').entries[0].error, 'backupFileInvalid');
    assert.equal(parseBackupJson('x.json', '{"format":"other"}').entries[0].error, 'backupFileInvalid');
    assert.equal(parseBackupJson('x.json', '{"format":"carbure-backup","dataset":"users","rows":[]}').entries[0].error, 'backupFileUnknown');
});

test('readBackupFile: the JSON files of a ZIP, or a JSON file', async () => {
    const zip = await createZip([...backupFiles(BACKUP), { name: 'README.txt', data: 'hello' }]);
    const fromZip = await readBackupFile('backup.zip', zip);
    assert.deepEqual(fromZip.entries.map(entry => [entry.file, entry.dataset, entry.count]), [['categories.json', 'categories', 1], ['rules.json', 'rules', 2]]);
    assert.equal(fromZip.manifest.created_at, BACKUP.created_at);

    const fromJson = await readBackupFile('rules.json', new TextEncoder().encode(backupFiles(BACKUP)[2].data));
    assert.deepEqual(fromJson.entries.map(entry => entry.dataset), ['rules']);
});

test('backupImportDatasets: only the selected and valid entries', () => {
    const datasets = backupImportDatasets([
        { dataset: 'categories', rows: [1], selected: true, error: null },
        { dataset: 'rules', rows: [2], selected: false, error: null },
        { dataset: null, rows: null, selected: true, error: 'backupFileInvalid' }
    ]);
    assert.deepEqual(datasets, { categories: [1] });
});

test('one file per dataset is selected for the import', () => {
    const module = createBackupModule(() => null);
    const a = { file: 'a/rules.json', dataset: 'rules', selected: true, error: null };
    const b = { file: 'b/rules.json', dataset: 'rules', selected: false, error: null };
    const bad = { file: 'x.json', dataset: null, selected: false, error: 'backupFileInvalid' };
    const context = { backupImportEntries: [a, b, bad] };
    module.methods.toggleBackupEntry.call(context, b);
    assert.deepEqual(context.backupImportEntries.map(entry => entry.selected), [false, true, false]);
    module.methods.toggleBackupEntry.call(context, context.backupImportEntries[2]);
    assert.deepEqual(context.backupImportEntries.map(entry => entry.selected), [false, true, false]);
});

test('API calls of the backup', async () => {
    const calls = mockFetch(url => json(url.endsWith('/backup') ? [{ name: 'rules', count: 2 }] : { imported: { rules: 2 } }));
    const api = createApiService('https://carbure.example.com/api', 't');

    assert.deepEqual(await api.fetchBackupInfo(), [{ name: 'rules', count: 2 }]);
    await api.exportBackup(['categories', 'rules']);
    assert.equal(calls[1].url, 'https://carbure.example.com/api/backup/export?datasets=categories%2Crules');
    assert.deepEqual(await api.importBackup({ rules: [] }), { imported: { rules: 2 } });
    assert.equal(calls[2].options.method, 'POST');
    assert.deepEqual(JSON.parse(calls[2].options.body), { datasets: { rules: [] } });
});

/**
 * Changes a field of the first entry of the central directory of a ZIP
 * @param {Uint8Array} zip - ZIP file (changed)
 * @param {number} field - Position in the entry (16: CRC-32, 24: size)
 * @param {number} value - New value
 */
function patchCentral(zip, field, value) {
    const view = new DataView(zip.buffer, zip.byteOffset, zip.byteLength);
    const central = view.getUint32(zip.length - 22 + 16, true);
    view.setUint32(central + field, value, true);
}

test('a deflated entry larger than its declared size is stopped (zip bomb)', async () => {
    const zip = await createZip([{ name: 'bomb.json', data: '0'.repeat(1024 * 1024) }]);
    // 1 MB compressed in a few KB, written as 100 bytes
    assert.ok(zip.length < 5000);
    patchCentral(zip, 24, 100);
    await assert.rejects(readZip(zip), { message: 'bomb.json: file too large' });
});

test('a declared size above the limit is refused before inflating', async () => {
    const zip = await createZip([{ name: 'big.json', data: 'x' }]);
    patchCentral(zip, 24, ZIP_MAX_ENTRY_SIZE + 1);
    await assert.rejects(readZip(zip), { message: 'big.json: file too large' });
});

test('a damaged entry is refused: CRC-32 or size that differs', async () => {
    const zip = await createZip([{ name: 'rules.json', data: '{"a":1}' }]);
    patchCentral(zip, 16, 12345);
    await assert.rejects(readZip(zip), { message: 'rules.json: damaged file' });

    // Deflated content longer than the size written
    const longer = await createZip([{ name: 'rules.json', data: '{"a":1}' }]);
    patchCentral(longer, 24, 3);
    await assert.rejects(readZip(longer), { message: 'rules.json: file too large' });

    // Stored entry (empty content) shorter than the size written
    const stored = await createZip([{ name: 'a.json', data: '' }]);
    patchCentral(stored, 24, 1);
    await assert.rejects(readZip(stored), { message: 'a.json: damaged file' });
});

test('a truncated ZIP is refused', async () => {
    const zip = await createZip([{ name: 'rules.json', data: 'x'.repeat(1000) }]);
    await assert.rejects(readZip(zip.subarray(0, zip.length - 30)));
});

test('a backup of a later version of the format is refused', () => {
    const later = { format: 'carbure-backup', version: BACKUP_VERSION + 1, dataset: 'rules', rows: [] };
    assert.equal(parseBackupJson('rules.json', JSON.stringify(later)).entries[0].error, 'backupFileVersion');
    assert.equal(parseBackupJson('x.json', JSON.stringify({ ...later, version: 'two' })).entries[0].error, 'backupFileVersion');
    // The version of this Carbure, or none given: read
    assert.equal(parseBackupJson('rules.json', JSON.stringify({ ...later, version: BACKUP_VERSION })).entries[0].error, null);
    assert.equal(parseBackupJson('rules.json', JSON.stringify({ ...later, version: undefined })).entries[0].error, null);
});
