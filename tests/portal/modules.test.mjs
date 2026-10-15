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
            { bankId: '00012345678@bnp', account_number: '00012345678', bank_name: 'bnp', label: 'Compte joint' },
            { bankId: '999@bnp', account_number: '999', bank_name: 'bnp', label: null },
        ],
    };
    context.accountName = methods.accountName.bind(context);
    context.bankIdName = methods.bankIdName.bind(context);

    assert.equal(context.accountName(context.bankAccounts[0]), 'Compte joint');
    assert.equal(context.accountName(context.bankAccounts[1]), 'BNP ···999');
    // A transaction of a followed account: the name of the account, not its own label
    assert.equal(context.accountName({ bank_name: 'bnp', account_number: '00012345678', label: 'CB PAUL' }), 'Compte joint');
    assert.equal(context.accountName({ bank_name: 'lcl', account_number: '11112222', label: 'CB PAUL' }), 'LCL ···2222');
    // Imported statement file: no bank
    assert.equal(context.accountName({ bank_name: null, account_number: 'FR761234' }), '···1234');
    assert.equal(context.bankIdName('00012345678@bnp'), 'Compte joint');
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
