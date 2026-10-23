/**
 * Notifications Module
 *
 * Notifications section of the profile page: for each kind of notification
 * (synchronization result, rule marked "notify", large expense, budget exceeded), the
 * channels it is sent on (push to the iOS app, when the administrator configured the
 * push service; email to the address of the profile), and the settings shown under the
 * label of their kind: the amount of a large expense, and for the budget alerts a global
 * threshold (percent of every budget, which can be turned off) and thresholds of
 * categories, used instead of it for their category. Each change is saved at once.
 *
 * @module notificationsModule
 */

/** Kinds of notification, in the order of the table */
export const NOTIFICATION_KINDS = ['sync', 'rule', 'expense', 'budget'];

/** Bounds of the budget alert threshold, in percent of the budget */
export const BUDGET_THRESHOLD_MIN = 50;
export const BUDGET_THRESHOLD_MAX = 200;

/**
 * Why a budget alert threshold is refused ('' when it is valid): an integer from
 * BUDGET_THRESHOLD_MIN to BUDGET_THRESHOLD_MAX
 * @param {number|string} value - Threshold typed
 * @returns {string} Translation key of the error, or ''
 */
export function budgetThresholdError(value) {
    const text = String(value ?? '').trim();
    const number = Number(text);
    if (text === '' || !Number.isInteger(number) || number < BUDGET_THRESHOLD_MIN || number > BUDGET_THRESHOLD_MAX) {
        return 'budgetThresholdInvalid';
    }
    return '';
}

/**
 * Why a large expense threshold is refused ('' when it is valid): empty (off) or a
 * positive amount
 * @param {number|string} value - Amount typed
 * @returns {string} Translation key of the error, or ''
 */
export function alertThresholdError(value) {
    const text = String(value ?? '').trim();
    if (text === '') {
        return '';
    }
    const number = Number(text);
    return Number.isFinite(number) && number >= 0 ? '' : 'alertThresholdInvalid';
}

/** Channels of a notification, in the order of the columns */
export const NOTIFICATION_CHANNELS = ['push', 'email'];

/**
 * Why the email channel cannot be used ('' when it can)
 * @param {Object|null} notifications - Answer of GET /user/notifications
 * @returns {string} Translation key of the reason, or ''
 */
export function emailUnavailableReason(notifications) {
    if (!notifications || notifications.email_available) {
        return '';
    }
    return notifications.smtp ? 'notificationsEmailNoAddress' : 'notificationsEmailNoSmtp';
}

/**
 * Why the push channel cannot be used ('' when it can): no API key of the push service
 * @param {Object|null} notifications - Answer of GET /user/notifications
 * @returns {string} Translation key of the reason, or ''
 */
export function pushUnavailableReason(notifications) {
    // Servers older than the push service do not send push_available: push available
    if (!notifications || notifications.push_available !== false) {
        return '';
    }
    return 'notificationsPushUnavailable';
}

/**
 * Creates the notifications module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the notifications data and methods
 */
export function createNotificationsModule(getApiService) {
    return {
        data() {
            return {
                // Answer of GET /user/notifications
                notifications: null,
                loadingNotifications: false,
                // Change being saved: "kind.channel"
                savingNotification: null,
                notificationKinds: NOTIFICATION_KINDS,
                notificationChannels: NOTIFICATION_CHANNELS,
                // Budget alert threshold typed (percent) and its error (translation key)
                budgetThreshold: 100,
                budgetThresholdErrorKey: '',
                savingBudgetThreshold: false,
                budgetThresholdMin: BUDGET_THRESHOLD_MIN,
                budgetThresholdMax: BUDGET_THRESHOLD_MAX,
                // Large expense threshold typed (amount, '' = off) and its error
                alertThreshold: '',
                alertThresholdErrorKey: '',
                savingAlertThreshold: false,
                // Global budget threshold on (all the budgets), and the thresholds of
                // categories: [{key, category (id as a string), threshold}]
                budgetAll: true,
                budgetRows: [],
                budgetRowsErrorKey: '',
                savingBudgetRules: false,
                nextBudgetRowKey: 0
            };
        },

        computed: {
            /**
             * Translation key of the reason why the email channel is disabled ('' if usable)
             * @returns {string}
             */
            emailUnavailable() {
                return emailUnavailableReason(this.notifications);
            },

            /**
             * Translation key of the reason why the push channel is disabled ('' if usable)
             * @returns {string}
             */
            pushUnavailable() {
                return pushUnavailableReason(this.notifications);
            },

            /**
             * Budgets that no threshold of a category uses yet, to add one
             * @returns {Array<{id: number, name: string}>}
             */
            freeBudgets() {
                const used = new Set(this.budgetRows.map(row => row.category));
                return (this.notifications?.budgets || []).filter(budget => !used.has(String(budget.id)));
            }
        },

        methods: {
            /**
             * Loads the notification channels of the authenticated user
             * @returns {Promise<void>}
             */
            async loadNotifications() {
                this.loadingNotifications = true;
                try {
                    this.notifications = await getApiService().fetchNotifications();
                    this.budgetThreshold = this.notifications.budget_threshold ?? 100;
                    this.budgetThresholdErrorKey = '';
                    this.readAlertSettings();
                } catch (error) {
                    this.notifications = null;
                    this.showToast(error.message);
                } finally {
                    this.loadingNotifications = false;
                }
            },

            /**
             * Copies the settings of the alerts answered by the server into the fields
             */
            readAlertSettings() {
                const amount = this.notifications.alert_threshold;
                this.alertThreshold = amount ? String(Number(amount)) : '';
                this.alertThresholdErrorKey = '';
                this.budgetAll = this.notifications.budget_all ?? true;
                this.budgetRows = (this.notifications.budget_thresholds || []).map(({ category, threshold }) => (
                    { key: this.nextBudgetRowKey++, category: String(category), threshold: String(threshold) }));
                this.budgetRowsErrorKey = '';
            },

            /**
             * Budgets a threshold of a category can be moved to: its own and those no
             * other threshold uses
             * @param {Object} row - The threshold of a category
             * @returns {Array<{id: number, name: string}>}
             */
            budgetRowOptions(row) {
                const used = new Set(this.budgetRows.filter(other => other !== row).map(other => other.category));
                return (this.notifications?.budgets || []).filter(budget => !used.has(String(budget.id)));
            },

            /**
             * Whether the threshold typed for a category is refused
             * @param {Object} row - The threshold of a category
             * @returns {boolean}
             */
            budgetRowInvalid(row) {
                return !!budgetThresholdError(row.threshold);
            },

            /**
             * Name of the category of a threshold, for the label of its button
             * @param {Object} row - The threshold of a category
             * @returns {string}
             */
            budgetRowName(row) {
                const budget = (this.notifications?.budgets || []).find(item => String(item.id) === row.category);
                return budget ? budget.name : row.category;
            },

            /**
             * Whether a channel can be changed: push only when the administrator configured
             * the push service; email only when it can be sent (an email channel on stays
             * changeable, to turn it off)
             * @param {string} kind - Kind of notification
             * @param {string} channel - push or email
             * @returns {boolean}
             */
            notificationDisabled(kind, channel) {
                if (channel === 'push') {
                    return !!this.pushUnavailable;
                }
                return !!this.emailUnavailable && !this.notifications.preferences[kind][channel];
            },

            /**
             * Id of the reason why a channel is unavailable, for aria-describedby (null if usable)
             * @param {string} channel - push or email
             * @returns {string|null}
             */
            notificationUnavailableId(channel) {
                const unavailable = channel === 'email' ? this.emailUnavailable : this.pushUnavailable;
                return unavailable ? `notifications-${channel}-unavailable` : null;
            },

            /**
             * Turns a channel of a kind on or off and saves it
             * @param {string} kind - Kind of notification
             * @param {string} channel - push or email
             * @param {boolean} enabled - New value
             * @returns {Promise<void>}
             */
            async setNotification(kind, channel, enabled) {
                const previous = this.notifications.preferences[kind][channel];
                this.notifications.preferences[kind][channel] = enabled;
                this.savingNotification = `${kind}.${channel}`;
                try {
                    this.notifications = await getApiService().updateNotifications({ [kind]: { [channel]: enabled } });
                    this.showToast(this.t('notificationsSaved'));
                } catch (error) {
                    this.notifications.preferences[kind][channel] = previous;
                    this.showToast(error.message);
                } finally {
                    this.savingNotification = null;
                }
            },

            /**
             * Checks and saves the budget alert threshold typed; an invalid one is not sent
             * and its error is shown next to the field
             * @returns {Promise<void>}
             */
            async saveBudgetThreshold() {
                this.budgetThresholdErrorKey = budgetThresholdError(this.budgetThreshold);
                if (this.budgetThresholdErrorKey) {
                    return;
                }
                this.savingBudgetThreshold = true;
                try {
                    this.notifications = await getApiService().updateBudgetThreshold(Number(this.budgetThreshold));
                    this.budgetThreshold = this.notifications.budget_threshold ?? Number(this.budgetThreshold);
                    this.showToast(this.t('notificationsSaved'));
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.savingBudgetThreshold = false;
                }
            },

            /**
             * Checks and saves the large expense threshold typed ('' turns the alert off);
             * an invalid one is not sent and its error is shown next to the field
             * @returns {Promise<void>}
             */
            async saveAlertThreshold() {
                this.alertThresholdErrorKey = alertThresholdError(this.alertThreshold);
                if (this.alertThresholdErrorKey) {
                    return;
                }
                this.savingAlertThreshold = true;
                try {
                    this.notifications = await getApiService().updateAlertSettings({ alertThreshold: String(this.alertThreshold ?? '').trim() });
                    this.readAlertSettings();
                    this.showToast(this.t('notificationsSaved'));
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.savingAlertThreshold = false;
                }
            },

            /**
             * Saves the budget alert settings sent; back to the saved ones if refused
             * @param {Object} settings - budgetAll and/or budgetThresholds
             * @returns {Promise<void>}
             */
            async saveBudgetRules(settings) {
                this.savingBudgetRules = true;
                try {
                    this.notifications = await getApiService().updateBudgetAlerts(settings);
                    this.showToast(this.t('notificationsSaved'));
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.readAlertSettings();
                    this.savingBudgetRules = false;
                }
            },

            /**
             * Turns the global budget threshold (all the budgets) on or off
             * @returns {Promise<void>}
             */
            async saveBudgetAll() {
                await this.saveBudgetRules({ budgetAll: this.budgetAll });
            },

            /**
             * Checks and saves the thresholds of categories; an invalid threshold is not
             * sent and its error is shown under the list
             * @returns {Promise<void>}
             */
            async saveBudgetRows() {
                this.budgetRowsErrorKey = this.budgetRows.map(row => budgetThresholdError(row.threshold)).find(Boolean) || '';
                if (this.budgetRowsErrorKey) {
                    return;
                }
                await this.saveBudgetRules({ budgetThresholds: this.budgetRows.map(({ category, threshold }) => ({ category, threshold: Number(threshold) })) });
            },

            /**
             * Adds a threshold for the first budget without one, at the global threshold
             * @returns {Promise<void>}
             */
            async addBudgetRow() {
                const budget = this.freeBudgets[0];
                if (!budget) {
                    return;
                }
                this.budgetRows.push({ key: this.nextBudgetRowKey++, category: String(budget.id), threshold: String(this.budgetThreshold) });
                await this.saveBudgetRows();
            },

            /**
             * Removes the threshold of a category: its budget follows the global threshold again
             * @param {Object} row - The threshold of a category
             * @returns {Promise<void>}
             */
            async removeBudgetRow(row) {
                this.budgetRows = this.budgetRows.filter(other => other !== row);
                await this.saveBudgetRows();
            }
        }
    };
}
