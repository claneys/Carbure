// Methods of the portal mixins (portal/modules) that do not need the DOM
import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createBudgetModule } from '../../portal/modules/budgetModule.js';
import { createTransactionModule, similarLabelQuery } from '../../portal/modules/transactionModule.js';
import { createRulesModule } from '../../portal/modules/rulesModule.js';
import { agentConfigurations } from '../../portal/modules/agentsModule.js';

const budget = createBudgetModule(() => null, () => null);
const transactions = createTransactionModule(() => null);
const rules = createRulesModule(() => null);

const CATEGORIES = [
    { id: 0, name: 'Non catégorisé', parent_category: 0, icon: 'help', color: '#9e9e9e' },
    { id: 1, name: 'Alimentation', parent_category: 0, icon: 'restaurant', color: 'green' },
    { id: 2, name: 'Supermarché', parent_category: 1, icon: 'shopping_cart', color: 'teal' },
    { id: 3, name: 'Restaurant', parent_category: 1, icon: '', color: '' },
    { id: 4, name: 'Logement', parent_category: 0, icon: 'home', color: 'blue' },
];

/** `this` of the portal for the transaction methods */
function transactionContext() {
    const context = {
        ...budget.methods,
        ruleCategories: CATEGORIES,
        // Icon font loaded: the stored names are kept, '' gives the default icon
        categoryIcon: item => item.icon || 'category',
        getTransactionIcon: type => `type-${type}`,
    };
    for (const [name, method] of Object.entries(transactions.methods)) {
        context[name] = method.bind(context);
    }
    context.categoryColor = budget.methods.categoryColor.bind(context);
    context.categoryBadgeStyle = budget.methods.categoryBadgeStyle.bind(context);
    return context;
}

test('budgetStatus: green up to 105 %, red beyond', () => {
    const status = progress => budget.methods.budgetStatus({ progress });
    assert.equal(status(0), 'ok');
    assert.equal(status(95), 'ok');
    assert.equal(status(105), 'ok');
    assert.equal(status(106), 'over');
    assert.equal(status('abc'), 'ok');
});

test('budgetGroups: expenses, incomes, off-budget or empty', () => {
    const groups = budget.computed.budgetGroups.call({ currentBudgetList: [
        { name: 'Logement', type: 'DEBIT', budget: '1000.00', consummed: 950 },
        { name: 'Vacances', type: 'DEBIT', budget: '0.00', consummed: 0 },
        { name: 'Salaire', type: 'CREDIT', budget: '0.00', consummed: 3200 },
        { name: 'Épargne', type: 'HORS-BUDGET', budget: '0.00', consummed: 500 },
    ] });
    assert.deepEqual(groups.expenses.map(i => i.name), ['Logement']);
    assert.deepEqual(groups.incomes.map(i => i.name), ['Salaire']);
    assert.deepEqual(groups.others.map(i => i.name), ['Vacances', 'Épargne']);
});

test('budgetSummary: totals of the budgeted expenses', () => {
    const summary = budget.computed.budgetSummary.call({ budgetGroups: { expenses: [
        { budget: '1000.00', consummed: 950 }, { budget: '400.00', consummed: 127.4 },
    ] } });
    assert.equal(summary.budget, 1400);
    assert.equal(Math.round(summary.consumed * 100) / 100, 1077.4);
    assert.equal(summary.progress, 77);
});

test('copySource: the month before the selected one, named in the button', () => {
    const t = (key, params = {}) => {
        const months = { 10: 'Octobre', 12: 'Décembre' };
        const text = key.startsWith('months.') ? months[key.slice(7)] : 'Recopier les budgets du mois précédent ({month})';
        return text.replace(/\{(\w+)\}/g, (match, name) => String(params[name]));
    };
    const context = { selectedMonth: 11, selectedYear: 2026, locale: 'fr', t };

    assert.deepEqual(budget.computed.copySource.call(context), { month: 10, year: 2026 });
    assert.equal(budget.computed.copySourceName.call({ ...context, copySource: { month: 10, year: 2026 } }), 'octobre');
    assert.equal(budget.computed.copyButtonLabel.call({ ...context, copySourceName: 'octobre' }),
        'Recopier les budgets du mois précédent (octobre)');

    // January is copied from December of the previous year
    const january = { ...context, selectedMonth: 1, selectedYear: 2026 };
    assert.deepEqual(budget.computed.copySource.call(january), { month: 12, year: 2025 });
    assert.equal(budget.computed.copySourceName.call({ ...january, copySource: { month: 12, year: 2025 } }), 'décembre');
});

test('categoryColor: color of the category, the primary color by default', () => {
    assert.equal(budget.methods.categoryColor({ color: 'teal' }), '#30b0c7');
    assert.equal(budget.methods.categoryColor({ color: '' }), 'var(--primary-color)');
});

test('the icon of a transaction is the one of its own category', () => {
    const context = transactionContext();
    assert.equal(context.transactionIcon({ category: '2', type: 7 }), 'shopping_cart');
    // A sub-category without icon: its own (default) icon, as on the categories page, not its parent's
    assert.equal(context.transactionIcon({ category: 3, type: 7 }), 'category');
    // Uncategorized: icon of the operation type, without category colors
    assert.equal(context.transactionIcon({ category: 0, type: 7 }), 'type-7');
    assert.equal(context.transactionIconStyle({ category: 0, type: 7 }), null);
    assert.deepEqual(context.transactionIconStyle({ category: 3 }), { color: 'color-mix(in srgb, var(--primary-color) var(--category-ink, 100%), white)', background: 'color-mix(in srgb, var(--primary-color) 14%, transparent)' });
});

test('categoryName and isUncategorized', () => {
    const context = transactionContext();
    assert.equal(context.categoryName({ category: 4 }), 'Logement');
    assert.equal(context.categoryName({ category: 0 }), '');
    assert.equal(context.isUncategorized({ category: '0' }), true);
    assert.equal(context.isUncategorized({ category: 2 }), false);
});

test('displayedTransactions, stats and totals', () => {
    const list = [
        { id: 1, amount: '3200.00', pointed: '1' },
        { id: 2, amount: '-82.40', pointed: '0' },
        { id: 3, amount: '-950.00', pointed: '1' },
    ];
    const context = { transactions: list, searchResults: [], isSearching: false, transactionFilter: 'unchecked' };
    const displayed = transactions.computed.displayedTransactions.call(context);
    assert.deepEqual(displayed.map(t => t.id), [2]);
    assert.equal(transactions.computed.displayedTotal.call({ displayedTransactions: displayed }), -82.4);
    assert.equal(transactions.computed.uncheckedCount.call(context), 1);

    const stats = transactions.computed.stats.call(context);
    assert.equal(stats.income, 3200);
    assert.equal(Math.round(stats.expense * 100) / 100, -1032.4);
});

test('recurring transactions: ids, filter and expected series', () => {
    const recurring = [
        { label: 'VIR SALAIRE ACME', ids: [1], status: 'received' },
        { label: 'PRLV LOYER AGENCE', ids: ['3'], status: 'received' },
        { label: 'CB NETFLIX.COM', ids: [], status: 'expected' },
    ];
    const recurringIds = transactions.computed.recurringIds.call({ recurring });
    assert.deepEqual([...recurringIds], [1, 3]);
    assert.deepEqual(transactions.computed.recurringExpected.call({ recurring }).map(s => s.label), ['CB NETFLIX.COM']);

    const list = [
        { id: 1, amount: '3200.00', pointed: '1' },
        { id: 2, amount: '-82.40', pointed: '0' },
        { id: 3, amount: '-950.00', pointed: '0' },
    ];
    const context = { transactions: list, searchResults: list, isSearching: false, transactionFilter: 'all', recurringOnly: true, recurringIds };
    context.isRecurring = transactions.methods.isRecurring.bind(context);
    assert.deepEqual(transactions.computed.displayedTransactions.call(context).map(t => t.id), [1, 3]);
    // Combined with the unchecked filter
    assert.deepEqual(transactions.computed.displayedTransactions.call({ ...context, transactionFilter: 'unchecked' }).map(t => t.id), [3]);
    // Not applied to the search results (other months)
    assert.equal(transactions.computed.displayedTransactions.call({ ...context, isSearching: true }).length, 3);
});

test('similarLabelQuery: the merchant, without the kind of operation nor the references', () => {
    // Labels of the data set (tests/e2e/server/seed.php) and as woob gives them
    assert.equal(similarLabelQuery('CB BOULANGERIE PAUL'), 'BOULANGERIE PAUL');
    assert.equal(similarLabelQuery('CB BOULANGERIE DU MARCHE'), 'BOULANGERIE DU MARCHE');
    assert.equal(similarLabelQuery('CB NETFLIX.COM'), 'NETFLIX.COM');
    assert.equal(similarLabelQuery('PRLV EDF CLIENTS PARTICULIERS'), 'EDF CLIENTS PARTICULIERS');
    assert.equal(similarLabelQuery('VIR SALAIRE ACME'), 'SALAIRE ACME');
    assert.equal(similarLabelQuery('VIR LIVRET A'), 'LIVRET A');
    assert.equal(similarLabelQuery('FACTURE CARTE DU 040126 BOULANGERIE CARTE 4974'), 'BOULANGERIE');
    assert.equal(similarLabelQuery('PRLV SEPA EDF MDT/123'), 'EDF');
    assert.equal(similarLabelQuery('VIR SEPA INST LOYER 12/01 REF 998877'), 'LOYER');
    assert.equal(similarLabelQuery('  cb   sushi shop  '), 'SUSHI SHOP');
    // Nothing meaningful left: the label itself
    assert.equal(similarLabelQuery('CB 123456'), 'CB 123456');
    assert.equal(similarLabelQuery('LOYER'), 'LOYER');
    assert.equal(similarLabelQuery(''), '');
});

test('searchSimilarTransactions: closes the detail and searches like a typed search', async () => {
    const searched = [];
    const context = {
        transactionDetail: { id: 1, label: 'CB BOULANGERIE PAUL' },
        searchQuery: '',
        searchTimer: null,
        runSearch() { searched.push(this.searchQuery); },
    };
    await transactions.methods.searchSimilarTransactions.call(context);
    assert.equal(context.transactionDetail, null);
    assert.equal(context.searchQuery, 'BOULANGERIE PAUL');
    assert.deepEqual(searched, ['BOULANGERIE PAUL']);
});

test('isSearching: from 2 characters', () => {
    assert.equal(transactions.computed.isSearching.call({ searchQuery: ' a ' }), false);
    assert.equal(transactions.computed.isSearching.call({ searchQuery: 'ed' }), true);
});

test('ruleCategoryOptions: each parent followed by its sub-categories, without the default one', () => {
    const options = rules.computed.ruleCategoryOptions.call({ ruleCategories: CATEGORIES });
    assert.deepEqual(options.map(o => o.label), ['Alimentation', 'Alimentation › Restaurant', 'Alimentation › Supermarché', 'Logement']);
});

test('agentConfigurations: the MCP URL and the token in each configuration', () => {
    const url = 'https://carbure.example.com/api/mcp';
    const configs = agentConfigurations(url, 'tok-123');
    assert.deepEqual(configs.map(c => c.id), ['claude-code', 'chatgpt', 'cursor', 'vscode', 'gemini']);
    // Counted, not searched with includes(): a test, not a check of a URL
    const occurrences = (text, part) => text.split(part).length - 1;
    for (const config of configs) {
        assert.ok(occurrences(config.code, url) > 0, config.id);
        assert.ok(occurrences(config.code, 'tok-123') > 0, config.id);
    }
    assert.deepEqual(JSON.parse(configs.find(c => c.id === 'cursor').code), { mcpServers: { carbure: { url, headers: { Authorization: 'Bearer tok-123' } } } });
});

test('import: base64 of a file, by chunks', async () => {
    const { toBase64 } = await import('../../portal/modules/importModule.js');
    const text = 'Date;Libellé;Montant\n'.repeat(5000);
    const bytes = new TextEncoder().encode(text);
    assert.equal(toBase64(bytes.buffer), Buffer.from(bytes).toString('base64'));
    assert.equal(toBase64(new ArrayBuffer(0)), '');
});

test('import: how a CSV file was read, with the profile of its bank or not', async () => {
    const { createImportModule } = await import('../../portal/modules/importModule.js');
    const context = { t: (key, params) => `${key}${params ? ':' + params.bank : ''}` };
    const label = createImportModule(() => null).methods.importBankLabel.bind(context);
    assert.equal(label({ format: 'csv', bank: { id: 'boursorama', name: 'Boursorama / BoursoBank' } }), 'importBankRecognized:Boursorama / BoursoBank');
    assert.equal(label({ format: 'csv', bank: null }), 'importBankGeneric');
    // A server without the bank field: the columns were guessed
    assert.equal(label({ format: 'csv' }), 'importBankGeneric');
    // Other formats are read the same for every bank
    assert.equal(label({ format: 'ofx', bank: null }), null);
    assert.equal(label(null), null);
});

test('config: curl command of the synchronization', async () => {
    const { syncCommand } = await import('../../portal/modules/configModule.js');
    assert.equal(syncCommand('https://x.fr/api/bank/sync', 'abc'), 'curl -sN -H "X-Sync-Token: abc" "https://x.fr/api/bank/sync"');
    assert.equal(syncCommand('https://x.fr/api/bank/sync', 'abc', '123@bnp'), 'curl -sN -H "X-Sync-Token: abc" "https://x.fr/api/bank/sync?account=123%40bnp"');
    assert.equal(syncCommand('https://x.fr/api/bank/sync', null), 'curl -sN "https://x.fr/api/bank/sync"');
});

test('accountName: the name given to the account, else the bank and the end of the number', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { methods } = createAccountsModule(() => null);
    const context = {
        bankAccounts: [
            { bankId: 'DEMO0000001@bnp', account_number: 'DEMO0000001', bank_name: 'bnp', label: 'Compte joint' },
            { bankId: '999@bnp', account_number: '999', bank_name: 'bnp', label: null },
        ],
    };
    context.accountName = methods.accountName.bind(context);
    context.bankIdName = methods.bankIdName.bind(context);

    assert.equal(context.accountName(context.bankAccounts[0]), 'Compte joint');
    assert.equal(context.accountName(context.bankAccounts[1]), 'BNP ···999');
    // A transaction of a followed account: the name of the account, not its own label
    assert.equal(context.accountName({ bank_name: 'bnp', account_number: 'DEMO0000001', label: 'CB PAUL' }), 'Compte joint');
    assert.equal(context.accountName({ bank_name: 'lcl', account_number: '11112222', label: 'CB PAUL' }), 'LCL ···2222');
    // Imported statement file: no bank
    assert.equal(context.accountName({ bank_name: null, account_number: 'FR761234' }), '···1234');
    assert.equal(context.bankIdName('DEMO0000001@bnp'), 'Compte joint');
    assert.equal(context.bankIdName('fail@bank'), 'BANK ···fail');
});

test('sync: the debug logs of woob are not steps nor transactions', async () => {
    const { createSyncModule } = await import('../../portal/modules/syncModule.js');
    const { syncSteps } = createSyncModule(() => null).computed;
    const steps = syncSteps.call({
        syncStatus: 'error',
        syncLog: [
            'Syncing 1@bnp (coming)...',
            'Woob debug 1@bnp: DEBUG:woob.browser:Request: GET https://bank.example/accounts',
            'Woob debug 1@bnp: Error while calling woob',
            'Error syncing 1@bnp: Error while calling woob (exit code: 1): 500 Server Error',
            'Notifying users of 1@bnp...',
            'Synchronization complete',
        ],
    });
    assert.deepEqual(steps.global, []);
    assert.deepEqual(steps.accounts[0].steps.map(s => [s.kind, s.status, s.count]), [['coming', 'error', 0], ['notify', 'ok', 0]]);
});

test('sync: a failure of the coming transactions does not fail the account', async () => {
    const { createSyncModule } = await import('../../portal/modules/syncModule.js');
    const { syncSteps, syncErrors } = createSyncModule(() => null).computed;
    const context = {
        syncStatus: 'success',
        syncLog: [
            'Syncing 1@bnp (coming)...',
            'Coming failed 1@bnp: Error while calling woob (exit code: 1): NotImplementedError',
            'Syncing 1@bnp (history)...',
            'Done 1@bnp (history): 3 received, 2 new',
            'Waiting for a validation 1@bnp: new attempt in 15 s (1/1)',
            'Notifying users of 1@bnp...',
            'Synchronization complete',
        ],
    };
    const steps = syncSteps.call(context);
    assert.deepEqual(steps.accounts[0].steps.map(s => [s.kind, s.status, s.count]),
        [['coming', 'error', 0], ['history', 'ok', 3], ['notify', 'ok', 0]]);
    assert.equal(steps.accounts[0].steps[0].error, 'Error while calling woob (exit code: 1): NotImplementedError');
    assert.equal(steps.accounts[0].steps[1].message, 'new attempt in 15 s (1/1)');
    // Not an error of the synchronization
    assert.deepEqual(syncErrors.call(context), []);
});

test('accounts: a bank already configured stays offered, with a free backend name', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { methods } = createAccountsModule(() => null);
    const context = {
        bankBackends: [{ name: 'bnp', module: 'bnp' }, { name: 'bnp_2', module: 'bnp' }, { name: 'cic_pro', module: 'cic' }],
        bankModules: [{ module: 'bnp', description: 'BNP Paribas' }, { module: 'boursorama', description: 'Boursorama' }],
    };
    context.findBackend = methods.findBackend.bind(context);
    assert.deepEqual(methods.otherBankModules.call(context).map(m => [m.module, m.configured]), [['bnp', true], ['boursorama', false]]);
    assert.equal(methods.freeBackendName.call(context, 'bnp'), 'bnp_3');
    assert.equal(methods.freeBackendName.call(context, 'boursorama'), 'boursorama');
    assert.equal(methods.backendModule.call(context, 'cic_pro'), 'cic');
    assert.equal(methods.backendModule.call(context, 'unknown'), 'unknown');
    assert.deepEqual(context.findBackend('unknown'), { name: 'unknown', module: 'unknown', label: null });
});

test('accounts: a connected bank shown by the name given to it, else by its bank', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { methods } = createAccountsModule(() => null);
    const context = { bankModules: [{ module: 'bnp', description: 'BNP Paribas' }] };

    assert.equal(methods.bankDescription.call(context, { name: 'bnp_2', module: 'bnp', label: 'Compte joint' }), 'Compte joint');
    assert.equal(methods.bankDescription.call(context, { name: 'bnp_2', module: 'bnp', label: null }), 'BNP Paribas');
    assert.equal(methods.bankDescription.call(context, { name: 'cic_pro', module: 'cic' }), 'cic_pro');
    assert.equal(methods.backendName.call(context, { name: 'bnp_2', module: 'bnp', label: 'Compte joint' }), 'Compte joint (bnp_2)');
});

test('accounts: renaming a connected bank updates it in the list', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const calls = [];
    const api = { renameBankBackend: async (backend, label) => { calls.push([backend, label]); return { name: backend, label: label || null }; } };
    const { methods } = createAccountsModule(() => api);
    const toasts = [];
    const context = {
        bankBackends: [{ name: 'bnp', module: 'bnp', label: null }, { name: 'cic_pro', module: 'cic', label: 'Pro' }],
        showToast: message => toasts.push(message),
        t: key => key,
    };
    for (const name of ['openBackendModal', 'submitBackendForm']) {
        context[name] = methods[name].bind(context);
    }

    context.openBackendModal(context.bankBackends[1]);
    assert.deepEqual(context.backendForm, { name: 'cic_pro', module: 'cic', label: 'Pro' });
    assert.equal(context.showBackendModal, true);
    context.backendForm.label = '  CIC pro  ';
    await context.submitBackendForm();
    assert.deepEqual(calls, [['cic_pro', 'CIC pro']]);
    assert.deepEqual(context.bankBackends.map(b => b.label), [null, 'CIC pro']);
    assert.equal(context.showBackendModal, false);
    assert.deepEqual(toasts, ['bankConnectionRenamed']);
});

test('accounts: removing a connected bank asks first in a modal, with the number of its followed accounts', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const removed = [];
    const api = { deleteBankBackend: async backend => { removed.push(backend); } };
    const { methods } = createAccountsModule(() => api);
    const toasts = [];
    const asked = [];
    let answer = false;
    let reloaded = 0;
    const context = {
        bankAccounts: [{ bank_name: 'bnp' }, { bank_name: 'bnp' }, { bank_name: 'cic_pro' }],
        bankModules: [{ module: 'bnp', description: 'BNP Paribas' }],
        showToast: message => toasts.push(message),
        t: (key, params) => `${key} ${JSON.stringify(params || {})}`,
        loadBankBackends: async () => { reloaded++; },
        loadBankAccounts: async () => { reloaded++; },
        // The confirmation modal: the action (removal) runs only when confirmed
        askConfirm: async options => {
            asked.push(options);
            if (answer) {
                await options.action();
            }
            return answer;
        },
    };
    for (const name of ['removeBackend', 'backendName']) {
        context[name] = methods[name].bind(context);
    }
    const backend = { name: 'bnp', module: 'bnp', label: null };

    // Cancelled: nothing removed
    await context.removeBackend(backend);
    assert.deepEqual(removed, []);
    assert.equal(asked[0].message, 'confirmDeleteBankConnection {"bank":"BNP Paribas (bnp)","count":2}');
    assert.equal(asked[0].busyLabel, 'bankConnectionDeleting {}');
    assert.equal(asked[0].danger, true);
    assert.equal(reloaded, 0);

    // Confirmed: removed by the action of the modal, then the lists reloaded
    answer = true;
    await context.removeBackend(backend);
    assert.deepEqual(removed, ['bnp']);
    assert.equal(reloaded, 2);
    assert.deepEqual(toasts, ['bankConnectionDeleted {}']);
});

test('confirm: answered by the buttons, the action kept running and its error shown in the modal', async () => {
    const { createConfirmModule } = await import('../../portal/modules/confirmModule.js');
    const { data, methods } = createConfirmModule();
    const context = { ...data() };
    for (const [name, method] of Object.entries(methods)) {
        context[name] = method.bind(context);
    }

    // Cancelled
    let asked = context.askConfirm({ title: 'T', message: 'M', confirmLabel: 'OK' });
    assert.equal(context.confirmDialog.message, 'M');
    context.cancelConfirm();
    assert.equal(await asked, false);
    assert.equal(context.confirmDialog, null);

    // Without action: confirmed at once
    asked = context.askConfirm({ title: 'T', message: 'M', confirmLabel: 'OK' });
    await context.acceptConfirm();
    assert.equal(await asked, true);

    // With an action: busy while it runs (no cancel, no second run), then closed
    let finish;
    let runs = 0;
    asked = context.askConfirm({ title: 'T', message: 'M', confirmLabel: 'OK', action: () => { runs++; return new Promise(resolve => { finish = resolve; }); } });
    const accepting = context.acceptConfirm();
    assert.equal(context.confirmDialog.busy, true);
    context.cancelConfirm();
    await context.acceptConfirm();
    assert.equal(runs, 1);
    assert.equal(context.confirmDialog.busy, true);
    finish();
    await accepting;
    assert.equal(await asked, true);
    assert.equal(context.confirmDialog, null);

    // A failed action: its error in the modal, which stays open and can be cancelled
    asked = context.askConfirm({ title: 'T', message: 'M', confirmLabel: 'OK', action: async () => { throw new Error('woob could not remove the bank'); } });
    await context.acceptConfirm();
    assert.equal(context.confirmDialog.busy, false);
    assert.equal(context.confirmDialog.error, 'woob could not remove the bank');
    context.cancelConfirm();
    assert.equal(await asked, false);
});

test('accounts: the woob repository is trusted only with the fingerprint shown, then updated', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const fingerprint = 'b5fc136c2d500b8483696a0ee722865842a4cc45';
    const calls = [];
    let trusted = false;
    const api = {
        fetchWoobStatus: async () => ({ repository: 'https://updates.woob.tech/3/main/', trusted, fingerprint, error: null }),
        updateWoob: async (onMessage, confirmed) => {
            calls.push(confirmed);
            if (!trusted && confirmed !== fingerprint) {
                onMessage(`Woob repository not trusted: ${fingerprint}`);
                return;
            }
            trusted = true;
            onMessage('100% All modules are up-to-date.');
            onMessage('Woob updated');
        },
    };
    const { methods, data } = createAccountsModule(() => api);
    const toasts = [];
    let reloaded = 0;
    const context = { ...data(), showToast: message => toasts.push(message), t: key => key, loadBankBackends: async () => { reloaded++; } };
    for (const name of ['openWoobUpdate', 'runWoobUpdate', 'onWoobUpdateLine', 'closeWoobUpdate']) {
        context[name] = methods[name].bind(context);
    }

    // Not trusted: shown, nothing run until the administrator trusts it
    await context.openWoobUpdate();
    assert.equal(context.woobUpdate.status.trusted, false);
    assert.deepEqual(calls, []);

    await context.runWoobUpdate(fingerprint);
    assert.deepEqual(calls, [fingerprint]);
    assert.equal(context.woobUpdate.done, true);
    assert.equal(context.woobUpdate.error, null);
    assert.deepEqual(toasts, ['woobUpdated']);
    // The banks woob supports are read again
    assert.equal(reloaded, 1);
    context.closeWoobUpdate();
    assert.equal(context.woobUpdate.open, false);

    // Trusted: started at once, without fingerprint
    await context.openWoobUpdate();
    assert.deepEqual(calls, [fingerprint, null]);
    assert.equal(context.woobUpdate.done, true);

    // A fingerprint refused by the server: shown again to compare, with the error
    context.onWoobUpdateLine(`Woob repository not trusted: ${'a'.repeat(40)}`);
    assert.equal(context.woobUpdate.status.trusted, false);
    assert.equal(context.woobUpdate.status.fingerprint, 'a'.repeat(40));
    assert.equal(context.woobUpdate.error, 'woobNotTrusted');
    context.onWoobUpdateLine('Woob update failed: network down');
    assert.equal(context.woobUpdate.error, 'network down');
});

test('accounts: the accounts found by woob, the followed ones ticked and not followed again', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { methods } = createAccountsModule(() => null);
    const context = {
        bankAccounts: [{ bankId: '111@bnp_2' }],
        bankModules: [{ module: 'bnp', description: 'BNP Paribas' }],
        discoveredAccounts: [],
        selectedAccounts: [],
    };
    for (const name of ['isFollowed', 'showDiscovered', 'accountsToFollow', 'backendName']) {
        context[name] = methods[name].bind(context);
    }

    context.showDiscovered([
        { bankId: '111@bnp_2', followed: false },
        { bankId: '222@bnp_2', followed: true },
        { bankId: '333@bnp_2', followed: false },
    ]);
    // Followed (in Carbure, or according to the API): ticked
    assert.deepEqual(context.selectedAccounts, ['111@bnp_2', '222@bnp_2']);
    assert.deepEqual(context.accountsToFollow(), []);
    context.selectedAccounts.push('333@bnp_2');
    assert.deepEqual(context.accountsToFollow().map(d => d.bankId), ['333@bnp_2']);

    assert.equal(context.backendName({ name: 'bnp_2', module: 'bnp' }), 'BNP Paribas (bnp_2)');
    assert.equal(context.backendName({ name: 'cic_pro', module: 'cic' }), 'cic_pro');
});

test('accountsByBank: the followed accounts under their bank, then the others', async () => {
    const { accountsByBank } = await import('../../portal/modules/accountsModule.js');
    const backends = [{ name: 'bnp', module: 'bnp' }, { name: 'cic_pro', module: 'cic' }, { name: 'bnp_2', module: 'bnp' }];
    const accounts = [
        { id: 1, bank_name: 'bank', bankId: 'fail@bank' },
        { id: 2, bank_name: 'bnp', bankId: '1@bnp' },
        { id: 3, bank_name: 'bnp_2', bankId: '2@bnp_2' },
        { id: 4, bank_name: 'bnp', bankId: '3@bnp' },
    ];
    const groups = accountsByBank(backends, accounts);
    // In the order of the connections, a connection without account kept, the others last
    assert.deepEqual(groups.map(g => g.key), ['bank-bnp', 'bank-cic_pro', 'bank-bnp_2', 'others']);
    assert.deepEqual(groups.map(g => g.accounts.map(a => a.id)), [[2, 4], [], [3], [1]]);
    assert.equal(groups[0].backend.name, 'bnp');
    assert.equal(groups[3].backend, null);

    // No account of an unknown bank: no "others" group; nothing at all: no group
    assert.deepEqual(accountsByBank(backends, accounts.slice(1)).map(g => g.key), ['bank-bnp', 'bank-cic_pro', 'bank-bnp_2']);
    assert.deepEqual(accountsByBank([], []), []);
    assert.deepEqual(accountsByBank(undefined, [{ id: 9, bank_name: 'x' }]).map(g => g.key), ['others']);
});

test('profile: sub-tabs, the two-factor one hidden before its migration; platform, model and versions of a device', async () => {
    const { createProfileModule, PROFILE_TABS } = await import('../../portal/modules/profileModule.js');
    assert.deepEqual(PROFILE_TABS.map(tab => tab.key), ['profile', 'security', 'notifications', 'devices']);
    const module = createProfileModule(() => null);
    const tabs = mfaStatus => module.computed.visibleProfileTabs.call({ profileTabs: PROFILE_TABS, mfaStatus }).map(tab => tab.key);
    assert.deepEqual(tabs(null), ['profile', 'security', 'notifications', 'devices']);
    assert.deepEqual(tabs({ available: true }), ['profile', 'security', 'notifications', 'devices']);
    assert.deepEqual(tabs({ available: false }), ['profile', 'notifications', 'devices']);

    const { devicePlatform } = module.methods;
    assert.deepEqual(devicePlatform({ platform: 'android' }), { icon: 'android', label: 'devicePlatform_android' });
    assert.deepEqual(devicePlatform({ platform: 'ios' }), { icon: 'phone_iphone', label: 'devicePlatform_ios' });
    // A server older than the platform column: iOS
    assert.deepEqual(devicePlatform({}), { icon: 'phone_iphone', label: 'devicePlatform_ios' });

    // Model and versions sent by the app, only those known
    const context = { t: (key, params) => `${key}:${params.version}` };
    const details = device => module.methods.deviceDetails.call(context, device);
    assert.equal(details({ model: 'iPhone16,1', os_version: 'iOS 26.0', app_version: '1.2' }), 'iPhone16,1 · iOS 26.0 · deviceAppVersion:1.2');
    assert.equal(details({ model: 'Google Pixel 8', os_version: null, app_version: ' ' }), 'Google Pixel 8');
    assert.equal(details({ os_version: 'Android 15', app_version: '1.0' }), 'Android 15 · deviceAppVersion:1.0');
    // A server older than these columns, or an app that does not send them
    assert.equal(details({ model: null, os_version: null, app_version: null }), '');
    assert.equal(details({}), '');
});

test('profile: the header toggle switches the theme at once and saves it in the profile', async () => {
    const { createProfileModule } = await import('../../portal/modules/profileModule.js');
    let choice = 'system';
    window.carbureTheme = {
        choice: () => choice,
        opposite: () => (choice === 'dark' ? 'light' : 'dark'),
        apply: value => { choice = value; return value; },
    };
    const saved = [];
    let failure = null;
    const api = {
        updateUserTheme: async (id, theme) => {
            saved.push([id, theme]);
            if (failure) {
                throw failure;
            }
            return { id, theme, email: 'marie@example.com' };
        },
    };
    const module = createProfileModule(() => api);
    const toasts = [];
    const context = {
        ...module.data(),
        currentUser: { id: 2, username: 'marie', theme: 'system' },
        t: key => key,
        showToast: message => toasts.push(message),
    };
    context.toggleTheme = module.methods.toggleTheme.bind(context);
    try {
        // Light displayed (system): dark chosen, the profile select in sync
        await context.toggleTheme();
        assert.equal(choice, 'dark');
        assert.deepEqual(saved, [[2, 'dark']]);
        assert.equal(context.currentUser.theme, 'dark');
        assert.equal(context.currentUser.username, 'marie');
        assert.equal(context.profileForm.theme, 'dark');
        assert.equal(context.savingTheme, false);

        // Not saved: back to the previous choice, the error shown
        failure = new Error('Network down');
        await context.toggleTheme();
        assert.deepEqual(saved[1], [2, 'light']);
        assert.equal(choice, 'dark');
        assert.equal(context.profileForm.theme, 'dark');
        assert.deepEqual(toasts, ['Network down']);

        // Label and icon: the action of the button
        const label = displayedTheme => module.computed.themeToggleLabel.call({ displayedTheme, t: key => key });
        const icon = displayedTheme => module.computed.themeToggleIcon.call({ displayedTheme });
        assert.equal(label('light'), 'switchToDarkTheme');
        assert.equal(icon('light'), 'dark_mode');
        assert.equal(label('dark'), 'switchToLightTheme');
        assert.equal(icon('dark'), 'light_mode');
    } finally {
        delete window.carbureTheme;
    }
});

test('config: a password is never shown, only typed to replace it', async () => {
    const { createConfigModule } = await import('../../portal/modules/configModule.js');
    const { methods, computed } = createConfigModule(() => null);
    const context = { ...methods };
    context.setConfig({ sections: [{ name: 'smtp', settings: [
        { key: 'smtp_host', value: 'smtp.example.com', editable: true, kind: 'text' },
        { key: 'smtp_password', value: '••••••••••••', set: true, secret: true, editable: true, kind: 'password' },
    ] }] });
    assert.deepEqual(context.configDraft, { smtp_host: 'smtp.example.com', smtp_password: '' });
    assert.deepEqual(computed.configChanges.call(context), {});

    context.configDraft.smtp_password = 'new-secret';
    assert.deepEqual(computed.configChanges.call(context), { smtp_password: 'new-secret' });
});

test('config: the test email shows the recipient, or the error of the server', async () => {
    const { createConfigModule } = await import('../../portal/modules/configModule.js');
    let answer = () => ({ sent: true, to: 'admin@example.com' });
    const calls = [];
    const api = { sendTestEmail: async to => { calls.push(to); return answer(); } };
    const { methods, data } = createConfigModule(() => api);
    const context = { ...data(), ...methods, t: (key, params) => `${key} ${JSON.stringify(params || {})}` };

    context.smtpTestTo = '  ';
    await context.sendTestEmail();
    assert.deepEqual(calls, ['']);
    assert.deepEqual(context.smtpTestResult, { ok: true, message: 'smtpTestSent {"to":"admin@example.com"}' });
    assert.equal(context.sendingTestEmail, false);

    answer = () => { throw new Error('The SMTP server refused the recipient: 550'); };
    context.smtpTestTo = 'refused@example.com';
    await context.sendTestEmail();
    assert.deepEqual(calls, ['', 'refused@example.com']);
    assert.deepEqual(context.smtpTestResult, { ok: false, message: 'The SMTP server refused the recipient: 550' });
});

test('config: registration on the push service after consent, renewal after confirmation, test push', async () => {
    const { createConfigModule, pushTestOutcome } = await import('../../portal/modules/configModule.js');
    const configWithKey = set => ({ file: 'conf/prod.ini', writable: true, sections: [{ name: 'push', settings: [
        { key: 'push_api_key', value: set ? '••••••••••••' : '', set, secret: true, editable: true, kind: 'password' },
    ] }] });
    const KEY = `cpk_0123456789abcdef_${'ab'.repeat(32)}`;
    const calls = [];
    let registered = false;
    let consent = null;
    let relay = 'https://relay.example.org';
    let testAnswer = () => ({ sent: 1, devices: 2, results: [] });
    const api = {
        registerPush: async given => {
            calls.push(given ? 'register+consent' : 'register');
            if (given) {
                consent = { at: '2026-10-17T08:00:00Z', url: relay };
            }
            const renewed = registered;
            registered = true;
            return { registered: true, renewed, key_id: 'abc' };
        },
        fetchPushInfo: async () => ({ url: relay, official: false, registered, api_key: registered ? KEY : '', consent, consent_valid: !!consent && consent.url === relay }),
        fetchConfig: async () => configWithKey(registered),
        fetchPushStatus: async () => ({ relay: { reachable: true, status: 'ok', version: '1.0.0', error: null }, key: { state: registered ? 'valid' : 'none' } }),
        sendTestPush: async () => testAnswer(),
    };
    const { methods, data, computed } = createConfigModule(() => api);
    const context = { ...data(), ...methods, t: (key, params) => `${key} ${JSON.stringify(params || {})}` };
    Object.defineProperty(context, 'pushKeySet', { get: () => computed.pushKeySet.call(context) });
    Object.defineProperty(context, 'pushKeyDisplay', { get: () => computed.pushKeyDisplay.call(context) });
    context.setConfig(configWithKey(false));
    assert.equal(context.pushKeySet, false);

    // First registration: the consent dialog, nothing sent until it is ticked
    await context.registerPush();
    assert.deepEqual(calls, []);
    assert.equal(context.pushConsent.accepted, false);
    assert.equal(context.pushInfo.url, 'https://relay.example.org');
    await context.acceptPushConsent();
    assert.deepEqual(calls, []);
    context.closePushConsent();
    assert.equal(context.pushConsent, null);

    await context.registerPush();
    context.pushConsent.accepted = true;
    await context.acceptPushConsent();
    assert.equal(context.pushConsent, null);
    assert.deepEqual(calls, ['register+consent']);
    assert.deepEqual(context.pushRegisterResult, { ok: true, message: 'pushRegistered {"key":"abc"}' });
    assert.equal(context.pushKeySet, true);
    assert.equal(context.registeringPush, false);
    assert.equal(context.pushInfo.consent_valid, true);
    // The API key: its public id only, in full once revealed, hidden again on reload
    assert.equal(context.pushKeyDisplay, `cpk_0123456789abcdef_${'•'.repeat(12)}`);
    context.pushKeyRevealed = true;
    assert.equal(context.pushKeyDisplay, KEY);
    await context.loadPushInfo();
    assert.equal(context.pushKeyRevealed, false);

    // A key already saved, the consent given for this relay: renewed only after confirmation
    window.confirm = () => false;
    await context.registerPush();
    assert.deepEqual(calls, ['register+consent']);
    window.confirm = () => true;
    await context.registerPush();
    assert.deepEqual(calls, ['register+consent', 'register']);
    assert.equal(context.pushRegisterResult.message, 'pushRenewed {"key":"abc"}');

    // Another relay: the consent asked again
    relay = 'https://other.example.org';
    await context.registerPush();
    assert.equal(context.pushConsent.accepted, false);
    assert.equal(calls.length, 2);
    context.closePushConsent();

    // The push service unreadable: nothing asked, the error shown
    const fetchPushInfo = api.fetchPushInfo;
    api.fetchPushInfo = async () => { throw new Error('down'); };
    await context.registerPush();
    assert.deepEqual(context.pushRegisterResult, { ok: false, message: 'pushInfoError {}' });
    assert.equal(context.pushConsent, null);
    api.fetchPushInfo = fetchPushInfo;

    relay = 'https://relay.example.org';
    api.registerPush = async () => { throw new Error('Push service registration refused'); };
    await context.registerPush();
    assert.deepEqual(context.pushRegisterResult, { ok: false, message: 'Push service registration refused' });

    await context.sendTestPush();
    assert.deepEqual(context.pushTestResult, { ok: true, message: 'pushTestSent {"count":1,"devices":2}' });
    testAnswer = () => { throw new Error('No device'); };
    await context.sendTestPush();
    assert.deepEqual(context.pushTestResult, { ok: false, message: 'No device' });
    assert.equal(context.sendingTestPush, false);

    assert.deepEqual(pushTestOutcome({ sent: 0, devices: 2, results: [
        { device: 'a', sent: false, status: 'unregistered', error: 'Unregistered' },
        { device: 'b', sent: false, status: 'error', error: null },
    ] }), { ok: false, key: 'pushTestFailed', params: { errors: 'Unregistered, error' } });
});

test('notifications: why the push channel is disabled', async () => {
    const { pushUnavailableReason, createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    assert.equal(pushUnavailableReason(null), '');
    assert.equal(pushUnavailableReason({ push_available: true }), '');
    // A server older than the push service: available
    assert.equal(pushUnavailableReason({}), '');
    assert.equal(pushUnavailableReason({ push_available: false }), 'notificationsPushUnavailable');

    const { methods } = createNotificationsModule(() => null);
    const context = { ...methods, pushUnavailable: 'notificationsPushUnavailable', emailUnavailable: '',
        notifications: { preferences: { sync: { push: true, email: false }, rule: { push: false, email: false } } } };
    // Push unavailable: every push channel disabled, on or off
    assert.equal(context.notificationDisabled('sync', 'push'), true);
    assert.equal(context.notificationDisabled('rule', 'push'), true);
    assert.equal(context.notificationDisabled('rule', 'email'), false);
    assert.equal(context.notificationUnavailableId('push'), 'notifications-push-unavailable');
    assert.equal(context.notificationUnavailableId('email'), null);
});

test('notifications: why the email channel is disabled', async () => {
    const { emailUnavailableReason } = await import('../../portal/modules/notificationsModule.js');
    assert.equal(emailUnavailableReason(null), '');
    assert.equal(emailUnavailableReason({ smtp: true, address: 'a@example.com', email_available: true }), '');
    assert.equal(emailUnavailableReason({ smtp: false, address: 'a@example.com', email_available: false }), 'notificationsEmailNoSmtp');
    assert.equal(emailUnavailableReason({ smtp: true, address: null, email_available: false }), 'notificationsEmailNoAddress');
});

test('notifications: the budget alert threshold is an integer from 50 to 200', async () => {
    const { budgetThresholdError, NOTIFICATION_KINDS } = await import('../../portal/modules/notificationsModule.js');
    assert.ok(NOTIFICATION_KINDS.includes('budget'));
    for (const value of [50, 100, '120', ' 200 ']) {
        assert.equal(budgetThresholdError(value), '', String(value));
    }
    for (const value of ['', null, undefined, 49, 201, '80.5', 'abc', -100]) {
        assert.equal(budgetThresholdError(value), 'budgetThresholdInvalid', String(value));
    }
});

test('notifications: the budget alert threshold is loaded, checked and saved', async () => {
    const { createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    const sent = [];
    let refuse = false;
    const api = {
        fetchNotifications: async () => ({ preferences: {}, smtp: true, address: 'a@example.com', email_available: true, budget_threshold: 90 }),
        updateBudgetThreshold: async threshold => {
            sent.push(threshold);
            if (refuse) {
                throw new Error('Refused');
            }
            return { preferences: {}, smtp: true, address: 'a@example.com', email_available: true, budget_threshold: threshold };
        },
    };
    const { methods, data } = createNotificationsModule(() => api);
    const toasts = [];
    const context = { ...data(), ...methods, t: key => key, showToast: message => toasts.push(message) };
    assert.equal(context.budgetThreshold, 100);

    await context.loadNotifications();
    assert.equal(context.budgetThreshold, 90);

    // Invalid: not sent, the error shown
    context.budgetThreshold = 300;
    await context.saveBudgetThreshold();
    assert.deepEqual(sent, []);
    assert.equal(context.budgetThresholdErrorKey, 'budgetThresholdInvalid');

    context.budgetThreshold = '80';
    await context.saveBudgetThreshold();
    assert.deepEqual(sent, [80]);
    assert.equal(context.budgetThresholdErrorKey, '');
    assert.equal(context.notifications.budget_threshold, 80);
    assert.deepEqual(toasts, ['notificationsSaved']);
    assert.equal(context.savingBudgetThreshold, false);

    refuse = true;
    await context.saveBudgetThreshold();
    assert.equal(toasts.at(-1), 'Refused');
    assert.equal(context.savingBudgetThreshold, false);
});

test('notifications: the large expense threshold is empty or a positive amount', async () => {
    const { alertThresholdError } = await import('../../portal/modules/notificationsModule.js');
    for (const value of ['', null, undefined, 0, '150', 99.5, ' 20 ']) {
        assert.equal(alertThresholdError(value), '', String(value));
    }
    for (const value of [-1, 'abc', '-0.5']) {
        assert.equal(alertThresholdError(value), 'alertThresholdInvalid', String(value));
    }
});

test('notifications: the large expense threshold and the budget watched are saved at once', async () => {
    const { createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    const sent = [];
    let refuse = false;
    let stored = { alert_threshold: 150, budget_category: null };
    const answer = () => ({ preferences: {}, smtp: true, address: 'a@example.com', email_available: true, budget_threshold: 100, budgets: [{ id: 3, name: 'Logement' }], ...stored });
    const api = {
        fetchNotifications: async () => answer(),
        updateAlertSettings: async settings => {
            sent.push(settings);
            if (refuse) {
                throw new Error('Refused');
            }
            stored = {
                alert_threshold: 'alertThreshold' in settings ? (Number(settings.alertThreshold) || null) : stored.alert_threshold,
                budget_category: 'budgetCategory' in settings ? (Number(settings.budgetCategory) || null) : stored.budget_category,
            };
            return answer();
        },
    };
    const { methods, data } = createNotificationsModule(() => api);
    const toasts = [];
    const context = { ...data(), ...methods, t: key => key, showToast: message => toasts.push(message) };

    await context.loadNotifications();
    assert.equal(context.alertThreshold, '150');
    assert.equal(context.budgetCategory, '');

    // Invalid amount: not sent
    context.alertThreshold = '-5';
    await context.saveAlertThreshold();
    assert.deepEqual(sent, []);
    assert.equal(context.alertThresholdErrorKey, 'alertThresholdInvalid');

    // Emptied: turned off
    context.alertThreshold = '';
    await context.saveAlertThreshold();
    assert.deepEqual(sent, [{ alertThreshold: '' }]);
    assert.equal(context.alertThreshold, '');
    assert.equal(context.alertThresholdErrorKey, '');

    context.budgetCategory = '3';
    await context.saveBudgetCategory();
    assert.deepEqual(sent.at(-1), { budgetCategory: '3' });
    assert.equal(context.budgetCategory, '3');
    assert.deepEqual(toasts, ['notificationsSaved', 'notificationsSaved']);

    // Refused: back to the budget saved
    refuse = true;
    context.budgetCategory = '';
    await context.saveBudgetCategory();
    assert.equal(context.budgetCategory, '3');
    assert.equal(toasts.at(-1), 'Refused');
    assert.equal(context.savingBudgetCategory, false);
    context.alertThreshold = '80';
    await context.saveAlertThreshold();
    assert.equal(toasts.at(-1), 'Refused');
    assert.equal(context.savingAlertThreshold, false);
});

test('notifications: a change is saved at once, undone if refused', async () => {
    const { createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    const preferences = () => ({ sync: { push: true, email: false }, rule: { push: true, email: false }, expense: { push: true, email: false } });
    const sent = [];
    let refuse = false;
    const api = {
        fetchNotifications: async () => ({ preferences: preferences(), smtp: false, address: 'a@example.com', email_available: false }),
        updateNotifications: async change => {
            sent.push(change);
            if (refuse) {
                throw new Error('Invalid value');
            }
            const result = { preferences: preferences(), smtp: true, address: 'a@example.com', email_available: true };
            result.preferences.sync.push = false;
            return result;
        },
    };
    const { methods, computed, data } = createNotificationsModule(() => api);
    const toasts = [];
    const context = { ...data(), ...methods, t: key => key, showToast: message => toasts.push(message) };
    Object.defineProperty(context, 'emailUnavailable', { get: () => computed.emailUnavailable.call(context) });

    await context.loadNotifications();
    assert.equal(context.emailUnavailable, 'notificationsEmailNoSmtp');
    // Email off and unavailable: disabled; push always changeable
    assert.equal(context.notificationDisabled('sync', 'email'), true);
    assert.equal(context.notificationDisabled('sync', 'push'), false);
    // An email channel already on stays changeable, to turn it off
    context.notifications.preferences.rule.email = true;
    assert.equal(context.notificationDisabled('rule', 'email'), false);

    await context.setNotification('sync', 'push', false);
    assert.deepEqual(sent, [{ sync: { push: false } }]);
    assert.equal(context.notifications.preferences.sync.push, false);
    assert.deepEqual(toasts, ['notificationsSaved']);
    assert.equal(context.savingNotification, null);

    refuse = true;
    await context.setNotification('expense', 'email', true);
    assert.equal(context.notifications.preferences.expense.email, false);
    assert.deepEqual(toasts, ['notificationsSaved', 'Invalid value']);

    // Unreadable: no table, the error shown
    api.fetchNotifications = async () => { throw new Error('Failed to read the notifications (500)'); };
    await context.loadNotifications();
    assert.equal(context.notifications, null);
    assert.equal(toasts.at(-1), 'Failed to read the notifications (500)');
});

test('config: status of the push service, the relay and the API key as shown', async () => {
    const { createConfigModule, pushRelayState, pushKeyState } = await import('../../portal/modules/configModule.js');
    assert.deepEqual(pushRelayState({ status: 'ok', version: '1.0.0' }), { level: 'ok', icon: 'check_circle', label: 'pushRelayOk' });
    assert.deepEqual(pushRelayState({ status: 'unreachable' }), { level: 'error', icon: 'error', label: 'pushRelayUnreachable' });
    assert.deepEqual(pushRelayState({ status: 'error' }), { level: 'error', icon: 'error', label: 'pushRelayError' });
    assert.equal(pushRelayState(null).label, 'pushStatusLoading');

    assert.deepEqual(pushKeyState({ state: 'valid', key_id: 'abc', devices: 2, used_today: 7, limits: { devices: 20, notifications_per_day: 500 } }),
        { level: 'ok', icon: 'check_circle', label: 'pushKeyValid', params: { id: 'abc', devices: 2, devicesLimit: 20, used: 7, usedLimit: 500 } });
    assert.equal(pushKeyState({ state: 'valid', key_id: 'abc', devices: 0, used_today: 0, limits: null }).params.devicesLimit, '?');
    assert.deepEqual(pushKeyState({ state: 'invalid', error: 'revoked' }), { level: 'error', icon: 'error', label: 'pushKeyInvalid', params: {} });
    assert.deepEqual(pushKeyState({ state: 'none' }), { level: 'warning', icon: 'warning', label: 'pushKeyNone', params: {} });
    assert.equal(pushKeyState({ state: 'unknown' }).label, 'pushKeyUnknown');
    assert.equal(pushKeyState(null).level, 'unknown');

    let answer = async () => ({ relay: { status: 'ok' }, key: { state: 'none' } });
    const api = {
        fetchPushInfo: async () => ({ url: 'https://api.carbure.org', registered: false, api_key: '', config_writable: true }),
        fetchPushStatus: () => answer(),
    };
    const { methods, data } = createConfigModule(() => api);
    const context = { ...data(), ...methods, t: key => key };
    await context.loadPushTab();
    assert.equal(context.pushInfo.url, 'https://api.carbure.org');
    assert.deepEqual(context.pushStatus.key, { state: 'none' });
    assert.equal(context.loadingPushStatus, false);

    answer = async () => { throw new Error('Failed to load the status of the push service'); };
    await context.loadPushStatus();
    assert.equal(context.pushStatus, null);
    assert.equal(context.pushStatusError, 'Failed to load the status of the push service');
});
