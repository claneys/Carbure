/**
 * Backup Module
 *
 * Administration > Backup (administrators): export of the chosen datasets in a ZIP
 * (manifest.json and one JSON file per dataset, made in the browser from the answer
 * of GET /backup/export), and import of a ZIP or of a dataset file: the files chosen
 * in the ZIP delete and replace their data (POST /backup/import), after a
 * confirmation.
 *
 * @module backupModule
 */

import { createZip, readZip, isZip } from '../utils/zip.js';

/** Name of the format of the backups (BackupData::FORMAT) */
export const BACKUP_FORMAT = 'carbure-backup';

/** Latest version of the format read (BackupData::VERSION): a later one may change the rows */
export const BACKUP_VERSION = 1;

/** Datasets, in the order of their dependencies, with their icon and label */
export const BACKUP_DATASETS = {
    categories: { icon: 'category', label: 'backupDatasetCategories' },
    rules: { icon: 'rule', label: 'backupDatasetRules' },
    budgets: { icon: 'savings', label: 'backupDatasetBudgets' },
    insights: { icon: 'insights', label: 'backupDatasetInsights' },
    transactions: { icon: 'receipt_long', label: 'backupDatasetTransactions' },
    deleted_transactions: { icon: 'delete_sweep', label: 'backupDatasetDeleted' }
};

/** Files offered by the file picker */
export const BACKUP_ACCEPT = '.zip,.json,application/zip,application/json';

/** Largest file read (bytes): 32 MB of JSON accepted by the server, compressed in a ZIP */
export const BACKUP_MAX_SIZE = 100 * 1024 * 1024;

/**
 * Files of the ZIP of an export: the manifest and one file per dataset
 * @param {Object} backup - Answer of GET /backup/export
 * @returns {Array<{name: string, data: string}>}
 */
export function backupFiles(backup) {
    const names = Object.keys(backup.datasets || {});
    const manifest = {
        format: backup.format,
        version: backup.version,
        created_at: backup.created_at,
        carbure: backup.carbure,
        schema: backup.schema,
        files: Object.fromEntries(names.map(name => [`${name}.json`, { dataset: name, count: backup.datasets[name].length }]))
    };
    return [
        { name: 'manifest.json', data: JSON.stringify(manifest, null, 2) },
        ...names.map(name => ({
            name: `${name}.json`,
            data: JSON.stringify({ format: backup.format, version: backup.version, dataset: name, rows: backup.datasets[name] })
        }))
    ];
}

/**
 * Name of the ZIP of an export
 * @param {Date} date - Date of the export
 * @returns {string} carbure-backup-YYYY-MM-DD.zip
 */
export function backupFileName(date = new Date()) {
    const pad = value => String(value).padStart(2, '0');
    return `${BACKUP_FORMAT}-${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}.zip`;
}

/**
 * Entry of the import list for a dataset
 * @param {string} file - Name of the file
 * @param {string} dataset - Dataset
 * @param {Array} rows - Rows
 * @returns {Object} {file, dataset, rows, count, error}
 */
function datasetEntry(file, dataset, rows) {
    if (!BACKUP_DATASETS[dataset]) {
        return { file, dataset, rows: null, count: 0, error: 'backupFileUnknown' };
    }
    return { file, dataset, rows, count: rows.length, error: null };
}

/**
 * Reads a JSON file of a backup: a dataset file, the manifest of a ZIP, or a whole
 * export (answer of GET /backup/export saved as is)
 * @param {string} file - Name of the file
 * @param {string} text - Content
 * @returns {{entries: Array<Object>, manifest: Object|null}} Entries {file, dataset, rows, count, error}
 */
export function parseBackupJson(file, text) {
    let content;
    try {
        content = JSON.parse(text);
    } catch (e) {
        content = null;
    }
    if (!content || typeof content !== 'object' || content.format !== BACKUP_FORMAT) {
        return { entries: [{ file, dataset: null, rows: null, count: 0, error: 'backupFileInvalid' }], manifest: null };
    }
    // Made by a later version of Carbure: its rows may not mean the same thing here
    if (!(Number(content.version ?? BACKUP_VERSION) <= BACKUP_VERSION)) {
        return { entries: [{ file, dataset: null, rows: null, count: 0, error: 'backupFileVersion' }], manifest: null };
    }
    if (typeof content.dataset === 'string' && Array.isArray(content.rows)) {
        return { entries: [datasetEntry(file, content.dataset, content.rows)], manifest: null };
    }
    if (content.datasets && typeof content.datasets === 'object') {
        const entries = Object.entries(content.datasets)
            .filter(([, rows]) => Array.isArray(rows))
            .map(([dataset, rows]) => datasetEntry(`${file} › ${dataset}`, dataset, rows));
        return { entries, manifest: content };
    }
    return { entries: [], manifest: content };
}

/**
 * Reads a file chosen for an import: a ZIP (its JSON files) or a JSON file
 * @param {string} name - Name of the file
 * @param {Uint8Array} bytes - Content
 * @returns {Promise<{entries: Array<Object>, manifest: Object|null}>}
 * @throws {Error} If the ZIP cannot be read
 */
export async function readBackupFile(name, bytes) {
    const decoder = new TextDecoder();
    if (!isZip(bytes)) {
        return parseBackupJson(name, decoder.decode(bytes));
    }
    const result = { entries: [], manifest: null };
    for (const file of await readZip(bytes)) {
        if (!file.name.toLowerCase().endsWith('.json')) {
            continue;
        }
        const { entries, manifest } = parseBackupJson(file.name, decoder.decode(file.data));
        result.entries.push(...entries);
        result.manifest = result.manifest || manifest;
    }
    return result;
}

/**
 * Body of POST /backup/import: the rows of the entries selected, by dataset
 * @param {Array<Object>} entries - Entries {dataset, rows, selected, error}
 * @returns {Object} {name: rows}
 */
export function backupImportDatasets(entries) {
    const datasets = {};
    for (const entry of entries) {
        if (entry.selected && !entry.error) {
            datasets[entry.dataset] = entry.rows;
        }
    }
    return datasets;
}

/**
 * Creates the backup module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the backup data and methods
 */
export function createBackupModule(getApiService) {
    return {
        data() {
            return {
                backupAccept: BACKUP_ACCEPT,
                // Datasets and their number of rows (GET /backup)
                backupDatasets: [],
                loadingBackup: false,
                // Datasets chosen for the export: {name: true}
                backupExportSelection: {},
                backupExporting: false,
                backupError: null,
                // File chosen for the import, its entries {file, dataset, rows, count, error, selected}
                backupImportFile: null,
                backupImportEntries: [],
                backupImportManifest: null,
                backupImportLoading: false,
                backupImportError: null,
                backupDragOver: false
            };
        },

        computed: {
            /**
             * Number of datasets chosen for the export
             * @returns {number}
             */
            backupExportCount() {
                return this.backupDatasets.filter(dataset => this.backupExportSelection[dataset.name]).length;
            },

            /**
             * Entries selected for the import
             * @returns {Array<Object>}
             */
            backupImportSelected() {
                return this.backupImportEntries.filter(entry => entry.selected && !entry.error);
            }
        },

        methods: {
            /**
             * Loads the datasets and their number of rows; all chosen for the export
             * @returns {Promise<void>}
             */
            async loadBackupInfo() {
                this.loadingBackup = true;
                this.backupError = null;
                try {
                    this.backupDatasets = await getApiService().fetchBackupInfo();
                    if (!Object.keys(this.backupExportSelection).length) {
                        this.backupExportSelection = Object.fromEntries(this.backupDatasets.map(dataset => [dataset.name, true]));
                    }
                } catch (err) {
                    this.backupError = err.message;
                } finally {
                    this.loadingBackup = false;
                }
            },

            /**
             * Icon of a dataset
             * @param {string} name - Dataset
             * @returns {string}
             */
            backupDatasetIcon(name) {
                return BACKUP_DATASETS[name]?.icon || 'description';
            },

            /**
             * Label of a dataset
             * @param {string} name - Dataset
             * @returns {string}
             */
            backupDatasetLabel(name) {
                return BACKUP_DATASETS[name] ? this.t(BACKUP_DATASETS[name].label) : (name || '—');
            },

            /** Chooses all the datasets for the export, or none */
            toggleBackupExportAll() {
                const value = this.backupExportCount < this.backupDatasets.length;
                this.backupExportSelection = Object.fromEntries(this.backupDatasets.map(dataset => [dataset.name, value]));
            },

            /**
             * Exports the chosen datasets and downloads the ZIP
             * @returns {Promise<void>}
             */
            async exportBackup() {
                const names = this.backupDatasets.map(dataset => dataset.name).filter(name => this.backupExportSelection[name]);
                if (!names.length) {
                    return;
                }
                this.backupExporting = true;
                this.backupError = null;
                try {
                    const backup = await getApiService().exportBackup(names);
                    const zip = await createZip(backupFiles(backup));
                    this.downloadBackup(new Blob([zip], { type: 'application/zip' }), backupFileName());
                    this.showToast(this.t('backupExportDone'), 3000);
                } catch (err) {
                    this.backupError = err.message;
                } finally {
                    this.backupExporting = false;
                }
            },

            /**
             * Saves a file in the downloads of the browser
             * @param {Blob} blob - Content
             * @param {string} name - Name of the file
             */
            downloadBackup(blob, name) {
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = name;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            },

            /** Back to the choice of the file to import */
            resetBackupImport() {
                this.backupImportFile = null;
                this.backupImportEntries = [];
                this.backupImportManifest = null;
                this.backupImportError = null;
                this.backupDragOver = false;
            },

            /**
             * File chosen with the picker
             * @param {Event} event - change event of the file input
             */
            onBackupPick(event) {
                const file = event.target.files && event.target.files[0];
                event.target.value = '';
                if (file) {
                    this.readBackupImport(file);
                }
            },

            /**
             * File dropped on the drop zone
             * @param {DragEvent} event - drop event
             */
            onBackupDrop(event) {
                this.backupDragOver = false;
                const file = event.dataTransfer && event.dataTransfer.files[0];
                if (file) {
                    this.readBackupImport(file);
                }
            },

            /**
             * Reads a ZIP or a JSON file and lists its datasets, all selected (one file
             * per dataset: the first one)
             * @param {File} file - The file
             * @returns {Promise<void>}
             */
            async readBackupImport(file) {
                this.resetBackupImport();
                if (file.size > BACKUP_MAX_SIZE) {
                    this.backupImportError = this.t('backupTooLarge', { size: Math.round(BACKUP_MAX_SIZE / 1024 / 1024) });
                    return;
                }
                this.backupImportLoading = true;
                try {
                    const { entries, manifest } = await readBackupFile(file.name, new Uint8Array(await file.arrayBuffer()));
                    const seen = new Set();
                    this.backupImportEntries = entries.map(entry => {
                        const selected = !entry.error && !seen.has(entry.dataset);
                        seen.add(entry.dataset);
                        return { ...entry, selected };
                    });
                    this.backupImportManifest = manifest;
                    this.backupImportFile = { name: file.name, size: file.size };
                    if (!entries.length) {
                        this.backupImportError = this.t('backupFileEmpty');
                    }
                } catch (err) {
                    this.backupImportError = this.t('backupFileUnreadable', { error: err.message });
                } finally {
                    this.backupImportLoading = false;
                }
            },

            /**
             * Selects or unselects a file of the import; one file per dataset
             * @param {Object} entry - Entry of the list
             */
            toggleBackupEntry(entry) {
                if (entry.error) {
                    return;
                }
                const selected = !entry.selected;
                this.backupImportEntries = this.backupImportEntries.map(other => {
                    if (other === entry) {
                        return { ...other, selected };
                    }
                    return selected && other.dataset === entry.dataset ? { ...other, selected: false } : other;
                });
            },

            /**
             * Asks the confirmation, then replaces the data of the selected datasets
             * @returns {Promise<void>}
             */
            async confirmBackupImport() {
                const selected = this.backupImportSelected;
                if (!selected.length) {
                    return;
                }
                const labels = selected.map(entry => this.backupDatasetLabel(entry.dataset)).join(', ');
                await this.askConfirm({
                    title: this.t('backupImportConfirmTitle'),
                    message: this.t('backupImportConfirm', { datasets: labels }),
                    confirmLabel: this.t('backupImportButton'),
                    busyLabel: this.t('backupImporting'),
                    icon: 'settings_backup_restore',
                    danger: true,
                    action: () => this.runBackupImport(selected)
                });
            },

            /**
             * Sends the import, then reloads what depends on it
             * @param {Array<Object>} selected - Entries to import
             * @returns {Promise<void>}
             * @throws {Error} If the server refuses it (shown in the confirmation)
             */
            async runBackupImport(selected) {
                const result = await getApiService().importBackup(backupImportDatasets(selected));
                const total = Object.values(result.imported || {}).reduce((sum, count) => sum + count, 0);
                this.resetBackupImport();
                this.showToast(this.t('backupImportDone', { count: total }), 4000);
                this.loadBackupInfo();
                this.loadCategories();
                this.loadPeriods();
            }
        }
    };
}
