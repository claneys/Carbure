/**
 * Config Module
 *
 * Settings and Sync tabs (Administration menu): the configuration file of the
 * instance by section, the secrets masked and only the safe settings editable
 * (the server checks every value; the SMTP password only replaced) with a test
 * email of the SMTP settings; the request that starts the bank synchronization
 * for a scheduler (cron, Synology task...).
 *
 * @module configModule
 */

/**
 * curl command that starts the synchronization
 * @param {string} url - URL of /api/bank/sync
 * @param {string|null} token - Synchronization token (null: none configured)
 * @param {string} account - Account to synchronize ('' for all)
 * @returns {string}
 */
export function syncCommand(url, token, account = '') {
    const target = account ? `${url}?account=${encodeURIComponent(account)}` : url;
    const header = token ? ` -H "X-Sync-Token: ${token}"` : '';
    // -N: the progress is streamed (Server-Sent Events)
    return `curl -sN${header} "${target}"`;
}

/**
 * Creates the config module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the config data and methods
 */
export function createConfigModule(getApiService) {
    return {
        data() {
            return {
                // Answer of GET /system/config
                configData: null,
                // Values being edited, by key
                configDraft: {},
                loadingConfig: false,
                savingConfig: false,
                configError: null,
                // Answer of GET /system/sync
                syncInfo: null,
                syncAccount: '',
                renewingSyncToken: false,
                // Test email: recipient ('' for the address of the administrator), result
                smtpTestTo: '',
                sendingTestEmail: false,
                smtpTestResult: null
            };
        },

        computed: {
            /**
             * Editable settings whose value changed
             * @returns {Object} New values by key
             */
            configChanges() {
                const changes = {};
                for (const section of this.configData ? this.configData.sections : []) {
                    for (const setting of section.settings) {
                        if (setting.editable && this.configDraft[setting.key] !== this.configValue(setting)) {
                            changes[setting.key] = this.configDraft[setting.key];
                        }
                    }
                }
                return changes;
            },

            /**
             * Number of settings changed
             * @returns {number}
             */
            configChangeCount() {
                return Object.keys(this.configChanges).length;
            },

            /**
             * The curl command of the synchronization
             * @returns {string}
             */
            syncCurl() {
                return this.syncInfo ? syncCommand(this.syncInfo.url, this.syncInfo.token, this.syncAccount) : '';
            },

            /**
             * A crontab line: every day at 6 am
             * @returns {string}
             */
            syncCron() {
                return this.syncInfo ? `0 6 * * * ${this.syncCurl} > /dev/null` : '';
            }
        },

        methods: {
            /**
             * Loads the configuration file (Settings tab)
             * @returns {Promise<void>}
             */
            async loadConfig() {
                this.loadingConfig = true;
                this.configError = null;
                try {
                    this.setConfig(await getApiService().fetchConfig());
                } catch (error) {
                    this.configError = error.message;
                } finally {
                    this.loadingConfig = false;
                }
            },

            /**
             * Loads the request of the synchronization, the token masked (Sync tab)
             * @returns {Promise<void>}
             */
            async loadSyncInfo() {
                this.configError = null;
                try {
                    this.syncInfo = await getApiService().fetchSyncInfo(false);
                } catch (error) {
                    this.configError = error.message;
                }
            },

            /**
             * Shows a configuration and resets the values being edited
             * @param {Object} config - Answer of GET /system/config
             */
            setConfig(config) {
                this.configData = config;
                const draft = {};
                for (const section of config.sections) {
                    for (const setting of section.settings) {
                        if (setting.editable) {
                            draft[setting.key] = this.configValue(setting);
                        }
                    }
                }
                this.configDraft = draft;
            },

            /**
             * Value of a setting in the form (the booleans as true/false)
             * @param {Object} setting - Setting
             * @returns {string|boolean}
             */
            configValue(setting) {
                if (setting.kind === 'bool') {
                    return setting.value === true;
                }
                // A password is never sent by the server: typed only to replace it
                if (setting.kind === 'password') {
                    return '';
                }
                return setting.value === null || setting.value === undefined ? '' : String(setting.value);
            },

            /** Cancels the changes */
            resetConfigDraft() {
                if (this.configData) {
                    this.setConfig(this.configData);
                }
                this.configError = null;
            },

            /**
             * Saves the settings changed
             * @returns {Promise<void>}
             */
            async saveConfig() {
                if (!this.configChangeCount) {
                    return;
                }
                this.savingConfig = true;
                this.configError = null;
                try {
                    const values = {};
                    for (const [key, value] of Object.entries(this.configChanges)) {
                        values[key] = typeof value === 'boolean' ? (value ? 'true' : 'false') : value;
                    }
                    this.setConfig(await getApiService().updateConfig(values));
                    this.showToast(this.t('configSaved'));
                } catch (error) {
                    this.configError = error.message;
                } finally {
                    this.savingConfig = false;
                }
            },

            /**
             * Name of a section of the file
             * @param {string} name - Section
             * @returns {string}
             */
            configSectionLabel(name) {
                const key = 'configSection_' + name;
                const label = this.t(key);
                return label === key ? name : label;
            },

            /**
             * Description of a setting ('' if there is none)
             * @param {string} key - Setting
             * @returns {string}
             */
            configHelp(key) {
                const name = 'configHelp_' + key;
                const help = this.t(name);
                return help === name ? '' : help;
            },

            /**
             * Sends a test email with the saved SMTP settings, to the address typed or
             * to the one of the administrator
             * @returns {Promise<void>}
             */
            async sendTestEmail() {
                if (this.sendingTestEmail) {
                    return;
                }
                this.sendingTestEmail = true;
                this.smtpTestResult = null;
                try {
                    const result = await getApiService().sendTestEmail(this.smtpTestTo.trim());
                    this.smtpTestResult = { ok: true, message: this.t('smtpTestSent', { to: result.to }) };
                } catch (error) {
                    this.smtpTestResult = { ok: false, message: error.message };
                } finally {
                    this.sendingTestEmail = false;
                }
            },

            /**
             * Shows the synchronization token, or masks it again
             * @returns {Promise<void>}
             */
            async toggleSyncToken() {
                try {
                    this.syncInfo = await getApiService().fetchSyncInfo(!!(this.syncInfo && this.syncInfo.masked));
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Copies the command with the real token
             * @param {boolean} cron - The crontab line instead of the command
             * @returns {Promise<void>}
             */
            async copySyncCommand(cron = false) {
                try {
                    const info = this.syncInfo.masked ? await getApiService().fetchSyncInfo(true) : this.syncInfo;
                    const command = syncCommand(info.url, info.token, this.syncAccount);
                    await this.copyText(cron ? `0 6 * * * ${command} > /dev/null` : command);
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Replaces the synchronization token after confirmation
             * @returns {Promise<void>}
             */
            async renewSyncToken() {
                const message = this.syncInfo && this.syncInfo.token ? this.t('syncTokenRenewConfirm') : this.t('syncTokenCreateConfirm');
                if (!window.confirm(message)) {
                    return;
                }
                this.renewingSyncToken = true;
                try {
                    await getApiService().renewSyncToken();
                    this.syncInfo = await getApiService().fetchSyncInfo(true);
                    this.showToast(this.t('syncTokenRenewed'));
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.renewingSyncToken = false;
                }
            }
        }
    };
}
