/**
 * Sync Module
 *
 * Starts a bank synchronization from the portal and shows its progress live
 * (Server-Sent Events read through fetch, which can send the JWT).
 *
 * @module syncModule
 */

/**
 * Creates a sync module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with sync data and methods
 */
export function createSyncModule(getApiService) {
    return {
        data() {
            return {
                bankAccounts: [],
                // Account being synchronized alone (bankId), null for all
                syncTarget: null,
                // The account is synchronized with a two-factor authentication allowed
                syncInteractive: false,
                // 'idle', 'running', 'success', 'error' or 'busy' (another sync is running)
                syncStatus: 'idle',
                syncLog: [],
                syncStartedAt: null,
                syncDuration: null,
                showSyncLog: false
            };
        },

        computed: {
            /**
             * Steps of the synchronization built from the progress messages:
             * per account (accounts listed, coming, history, notification), then categorization.
             * Each step: {kind, status: running|ok|error, count, error, message}
             * (message: last message of the bank, e.g. a validation to do in its app)
             * @returns {{accounts: Array<{id: string, steps: Array}>, global: Array}}
             */
            syncSteps() {
                const accounts = [];
                const global = [];
                let current = null;

                const close = status => {
                    if (current && current.status === 'running') {
                        current.status = status;
                    }
                };
                const account = id => {
                    let found = accounts.find(a => a.id === id);
                    if (!found) {
                        found = { id, steps: [] };
                        accounts.push(found);
                    }
                    return found;
                };

                for (const line of this.syncLog) {
                    let match;
                    if (line.startsWith('Woob debug ') || line.startsWith('Woob command ')) {
                        // Command run and debug logs of woob (woob_debug): only shown in the log
                        continue;
                    }
                    if ((match = line.match(/^Syncing (.+) \((accounts|coming|history)\)\.\.\.$/))) {
                        close('ok');
                        current = { kind: match[2], status: 'running', count: 0, created: null, error: null };
                        account(match[1]).steps.push(current);
                    } else if ((match = line.match(/^Done (.+) \((coming|history)\): (\d+) received, (\d+) new$/))) {
                        // Result of the step sent by the server
                        if (current && current.kind === match[2]) {
                            current.count = Number(match[3]);
                            current.created = Number(match[4]);
                            current.status = 'ok';
                        }
                    } else if ((match = line.match(/^Bank message (.+?): (.*)$/))) {
                        if (current && current.status === 'running') {
                            current.message = match[2];
                        }
                    } else if ((match = line.match(/^Coming failed (.+?): (.*)$/))) {
                        // The coming transactions failed: the history is still synchronized
                        if (current && current.kind === 'coming' && current.status === 'running') {
                            current.status = 'error';
                            current.error = match[2];
                        }
                    } else if ((match = line.match(/^Waiting for a validation (.+?): (.*)$/))) {
                        // Scheduled synchronization: new attempt after a validation in the bank app
                        if (current) {
                            current.message = match[2];
                        }
                    } else if ((match = line.match(/^Error syncing (.+?): (.*)$/))) {
                        if (current && current.status === 'running') {
                            current.status = 'error';
                            current.error = match[2];
                        } else {
                            account(match[1]).steps.push({ kind: 'sync', status: 'error', count: 0, error: match[2] });
                        }
                    } else if ((match = line.match(/^Notifying users of (.+)\.\.\.$/))) {
                        close('ok');
                        current = { kind: 'notify', status: 'running', count: 0, error: null };
                        account(match[1]).steps.push(current);
                    } else if (line.startsWith('Updating missing categories')) {
                        close('ok');
                        current = { kind: 'categories', status: 'running', count: 0, error: null };
                        global.push(current);
                    } else if (line.startsWith('Synchronization complete')) {
                        close('ok');
                    } else if (line.startsWith('Error')) {
                        global.push({ kind: 'error', status: 'error', count: 0, error: line });
                    } else if (current && current.status === 'running' && current.created === null && !line.includes('already in progress')) {
                        // Any other message is a transaction being saved (live count)
                        current.count++;
                    }
                }

                // The stream ended while a step was still running
                if (this.syncStatus !== 'running') {
                    close('error');
                }

                return { accounts, global };
            },

            /**
             * Errors reported during the last synchronization
             * @returns {Array<string>}
             */
            syncErrors() {
                return this.syncLog.filter(line => line.startsWith('Error'));
            }
        },

        methods: {
            /**
             * Whether a synchronization error asks a two-factor authentication, which
             * woob only starts in interactive mode
             * @param {string|null} error - Error of a synchronization step
             * @returns {boolean}
             */
            needsTwoFactor(error) {
                return /interactive mode to perform a two-factor authentication/i.test(error || '');
            },

            /**
             * Search of the woob issues about a bank module (known problems and fixes)
             * @param {string} bankId - Account "<number>@<backend>"
             * @returns {string} URL of the search on the woob GitLab
             */
            woobIssuesUrl(bankId) {
                // The module of the backend (bnp for bnp_2), known by the administrators
                const backend = String(bankId).split('@').pop();
                const module = typeof this.backendModule === 'function' ? this.backendModule(backend) : backend;
                return 'https://gitlab.com/search?group_id=11540390&project_id=25520182&scope=work_items'
                    + `&search=${encodeURIComponent(module)}&sort=created_desc`;
            },

            /**
             * Loads the bank accounts of the user
             * @returns {Promise<void>}
             */
            async loadBankAccounts() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }
                try {
                    this.bankAccounts = await apiService.fetchBankAccounts();
                } catch (error) {
                    this.error = error.message;
                }
            },

            /**
             * Starts a synchronization and follows its progress
             * @param {string|null} account - Synchronize only this account (bankId)
             * @param {boolean} interactive - Allow the bank to ask a two-factor authentication
             * @returns {Promise<void>}
             */
            async startSync(account = null, interactive = false) {
                if (this.syncStatus === 'running') {
                    return;
                }

                this.syncTarget = typeof account === 'string' ? account : null;
                this.syncInteractive = this.syncTarget !== null && interactive === true;
                this.syncStatus = 'running';
                this.syncLog = [];
                this.syncStartedAt = Date.now();
                this.syncDuration = null;

                try {
                    await getApiService().syncBanks(message => {
                        this.syncLog.push(message);
                        this.$nextTick(() => {
                            const log = document.querySelector('.sync-log');
                            if (log) {
                                log.scrollTop = log.scrollHeight;
                            }
                        });
                    }, this.syncTarget, this.syncInteractive);

                    const last = this.syncLog[this.syncLog.length - 1] || '';
                    if (last.includes('already in progress')) {
                        this.syncStatus = 'busy';
                    } else if (last.includes('complete')) {
                        this.syncStatus = this.syncErrors.length ? 'error' : 'success';
                    } else {
                        this.syncStatus = 'error';
                    }
                } catch (error) {
                    this.syncLog.push(`Error: ${error.message}`);
                    this.syncStatus = 'error';
                } finally {
                    this.syncDuration = Math.round((Date.now() - this.syncStartedAt) / 1000);
                    // New months may have transactions now
                    this.loadPeriods();
                    // Date and result of the last synchronization of each account
                    if (this.isAdmin) {
                        this.loadBankAccounts();
                    }
                }
            }
        }
    };
}
