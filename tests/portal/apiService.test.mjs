// services/apiService.js, with a fake fetch
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApiService } from '../../portal/services/apiService.js';

const BASE = 'https://carbure.example.com/api';

test('sends the token and decodes the JSON', async () => {
    const calls = mockFetch(() => json([{ id: 1 }]));
    const api = createApiService(BASE, 'jwt-token');

    assert.deepEqual(await api.fetchCategories(), [{ id: 1 }]);
    assert.equal(calls[0].url, `${BASE}/category`);
    assert.equal(calls[0].options.headers.Authorization, 'Bearer jwt-token');
    assert.equal(calls[0].options.headers['Content-Type'], 'application/json');
});

test('without token, no Authorization header', async () => {
    const calls = mockFetch(() => json({}));
    await createApiService(BASE).fetchMe();
    assert.equal(calls[0].options.headers.Authorization, undefined);
});

test('the error message of the API is the one of the exception', async () => {
    mockFetch(() => json({ error: 'The name must contain 1 to 50 characters', code: 400, uid: 'abc' }, 400));
    await assert.rejects(createApiService(BASE, 't').createCategory({ name: '' }), { message: 'The name must contain 1 to 50 characters' });

    mockFetch(() => new Response('<html>Bad gateway</html>', { status: 502 }));
    await assert.rejects(createApiService(BASE, 't').fetchUsers(), { message: 'Failed to fetch users (502)' });
});

test('a 401 reports the expired session', async () => {
    mockFetch(() => json({ error: 'Unauthorized' }, 401));
    let expired = 0;
    const api = createApiService(BASE, 'old-token', () => expired++);

    await assert.rejects(api.fetchTransactions(1, 2026));
    assert.equal(expired, 1);
});

test('a 503 of a Carbure not installed reports it, not another 503', async () => {
    let setup = 0;
    mockFetch(() => json({ error: 'Carbure is not installed yet', code: 503, setup: true }, 503));
    await assert.rejects(createApiService(BASE, 'old-token', null, () => setup++).fetchTransactions(1, 2026));
    assert.equal(setup, 1);

    // A proxy answering while the server restarts
    mockFetch(() => new Response('<html>Service unavailable</html>', { status: 503 }));
    await assert.rejects(createApiService(BASE, 'old-token', null, () => setup++).fetchTransactions(1, 2026));
    assert.equal(setup, 1);
});

test('the lists are always arrays', async () => {
    mockFetch(() => json({ unexpected: true }));
    const api = createApiService(BASE, 't');
    assert.deepEqual(await api.fetchTransactions(10, 2026), []);
    assert.deepEqual(await api.fetchBudget(10, 2026), []);
    assert.deepEqual(await api.fetchInsights(10, 2026), []);
    assert.deepEqual(await api.fetchUsers(), []);
});

test('a new list request cancels the previous one of the same kind', async () => {
    let release;
    const calls = mockFetch((url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
        release = () => resolve(json([{ id: 2 }]));
    }));
    const api = createApiService(BASE, 't');

    const first = api.fetchBudget(9, 2026);
    const second = api.fetchBudget(10, 2026, 4);
    await assert.rejects(first, { name: 'AbortError' });
    release();
    assert.deepEqual(await second, [{ id: 2 }]);
    assert.equal(calls[1].url, `${BASE}/budget?month=10&year=2026&category=4`);
});

test('abortAllExcept cancels the other pending lists', async () => {
    mockFetch((url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }));
    const api = createApiService(BASE, 't');
    const insights = api.fetchInsights(10, 2026);
    api.abortAllExcept('transactions');
    await assert.rejects(insights, { name: 'AbortError' });
});

test('updateBudget sets the budget of a category with POST /budget', async () => {
    const calls = mockFetch(() => json(true));
    await createApiService(BASE, 't').updateBudget(4, 10, 2026, 350);

    assert.equal(calls[0].url, `${BASE}/budget`);
    assert.equal(calls[0].options.method, 'POST');
    assert.deepEqual(JSON.parse(calls[0].options.body), { category: 4, amount: 350, month: 10, year: 2026 });
});

test('copyBudgets copies the previous month with POST /budget/copy', async () => {
    const calls = mockFetch(() => json({ month: '2026-11', source: '2026-10', copied: 3, overwrite: true }));
    const api = createApiService(BASE, 't');

    assert.deepEqual(await api.copyBudgets(11, 2026, true), { month: '2026-11', source: '2026-10', copied: 3, overwrite: true });
    assert.equal(calls[0].url, `${BASE}/budget/copy`);
    assert.equal(calls[0].options.method, 'POST');
    assert.deepEqual(JSON.parse(calls[0].options.body), { month: 11, year: 2026, overwrite: true });
});

test('saveInsight sends the chosen icon, null for the default one', async () => {
    const calls = mockFetch(() => json({ id: 3 }));
    const api = createApiService(BASE, 't');

    await api.saveInsight({ id: null, name: 'Loyer', color: 'blue', icon: 'home', sql: 'SELECT 1 AS amount' });
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(JSON.parse(calls[0].options.body).icon, 'home');

    await api.saveInsight({ id: 3, name: 'Loyer', color: 'blue', icon: '', sql: 'SELECT 1 AS amount' });
    assert.equal(calls[1].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[1].options.body), { id: 3, name: 'Loyer', color: 'blue', sql: 'SELECT 1 AS amount', icon: null });
});

test('updateUser only sends the fields given', async () => {
    const calls = mockFetch(() => json({ id: 2 }));
    const api = createApiService(BASE, 't');

    await api.updateUser(2, 'm@example.com', 'Marie', 'Martin', '   ', null, undefined);
    assert.equal(calls[0].url, `${BASE}/user?id=2`);
    assert.equal(calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[0].options.body), { email: 'm@example.com', firstname: 'Marie', lastname: 'Martin' });

    await api.updateUser(2, 'm@example.com', 'Marie', 'Martin', 'secret-1', 'en', 150, 1);
    assert.deepEqual(JSON.parse(calls[1].options.body), {
        email: 'm@example.com', firstname: 'Marie', lastname: 'Martin', is_admin: true, language: 'en', alertThreshold: '150', password: 'secret-1',
    });
});

test('syncBanks reads the progress events of one account', async () => {
    const stream = 'data: Syncing 1@bnp (coming)...\n\n: heartbeat\n\ndata: Done 1@bnp (coming): 1 received, 1 new\n\ndata: Synchronization complete\n\n';
    const calls = mockFetch(() => new Response(new Blob([stream]).stream(), { status: 200 }));
    const messages = [];

    await createApiService(BASE, 't').syncBanks(message => messages.push(message), '1@bnp');
    assert.equal(calls[0].url, `${BASE}/bank/sync?account=1%40bnp`);
    assert.deepEqual(messages, ['Syncing 1@bnp (coming)...', 'Done 1@bnp (coming): 1 received, 1 new', 'Synchronization complete']);
});

test('syncBanks asks the interactive mode for one account only', async () => {
    const calls = mockFetch(() => new Response(new Blob(['data: Synchronization complete\n\n']).stream(), { status: 200 }));
    const api = createApiService(BASE, 't');

    await api.syncBanks(() => {}, '1@bnp', true);
    await api.syncBanks(() => {}, null, true);
    assert.equal(calls[0].url, `${BASE}/bank/sync?account=1%40bnp&interactive=true`);
    assert.equal(calls[1].url, `${BASE}/bank/sync`);
});

test('syncBanks reports a refused synchronization', async () => {
    mockFetch(() => json({ error: 'Account not found' }, 404));
    await assert.rejects(createApiService(BASE, 't').syncBanks(() => {}, 'x@y'), { message: 'Account not found' });
});

test('the accounts of one bank connection are asked to woob with its name', async () => {
    const calls = mockFetch(() => json([{ bankId: '1@bnp_2' }]));
    const api = createApiService(BASE, 't');

    assert.deepEqual(await api.discoverBankAccounts('bnp_2'), [{ bankId: '1@bnp_2' }]);
    assert.equal(calls[0].url, `${BASE}/bank/discover?backend=bnp_2`);
    await api.discoverBankAccounts();
    assert.equal(calls[1].url, `${BASE}/bank/discover`);
});

test('notifications and test email requests', async () => {
    const calls = mockFetch(() => json({}));
    const api = createApiService(BASE, 't');

    await api.fetchNotifications();
    await api.updateNotifications({ sync: { email: true } });
    await api.sendTestEmail();
    await api.sendTestEmail('other@example.com');

    assert.equal(calls[0].url, `${BASE}/user/notifications`);
    assert.equal(calls[1].url, `${BASE}/user/notifications`);
    assert.equal(calls[1].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[1].options.body), { preferences: { sync: { email: true } } });
    assert.equal(calls[2].url, `${BASE}/system/smtp-test`);
    assert.equal(calls[2].options.method, 'POST');
    assert.deepEqual(JSON.parse(calls[2].options.body), {});
    assert.deepEqual(JSON.parse(calls[3].options.body), { to: 'other@example.com' });

    await api.registerPush();
    await api.sendTestPush();
    assert.equal(calls.at(-2).url, `${BASE}/system/push-register`);
    assert.equal(calls.at(-2).options.method, 'POST');
    assert.equal(calls.at(-1).url, `${BASE}/system/push-test`);
    assert.equal(calls.at(-1).options.method, 'POST');
    calls.splice(4);

    await api.updateBudgetThreshold(80);
    assert.equal(calls[4].url, `${BASE}/user/notifications`);
    assert.equal(calls[4].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[4].options.body), { budgetThreshold: '80' });

    // Settings of the alerts: only those sent, as strings ('' turns a setting off)
    await api.updateAlertSettings({ alertThreshold: 150.5 });
    await api.updateAlertSettings({ budgetCategory: '' });
    assert.equal(calls[5].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[5].options.body), { alertThreshold: '150.5' });
    assert.deepEqual(JSON.parse(calls[6].options.body), { budgetCategory: '' });
});
