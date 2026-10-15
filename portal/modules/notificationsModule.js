/**
 * Notifications Module
 *
 * Notifications section of the profile page: for each kind of notification
 * (synchronization result, rule marked "notify", large expense, budget exceeded), the
 * channels it is sent on (push to the iOS app, email to the address of the profile),
 * and the threshold of the budget alerts (percent of a budget). Each change is saved
 * at once.
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
                budgetThresholdMax: BUDGET_THRESHOLD_MAX
            };
        },

        computed: {
            /**
             * Translation key of the reason why the email channel is disabled ('' if usable)
             * @returns {string}
             */
            emailUnavailable() {
                return emailUnavailableReason(this.notifications);
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
                } catch (error) {
                    this.notifications = null;
                    this.showToast(error.message);
                } finally {
                    this.loadingNotifications = false;
                }
            },

            /**
             * Whether a channel can be changed: email only when it can be sent (a channel
             * on stays changeable, to turn it off)
             * @param {string} kind - Kind of notification
             * @param {string} channel - push or email
             * @returns {boolean}
             */
            notificationDisabled(kind, channel) {
                return channel === 'email' && !!this.emailUnavailable && !this.notifications.preferences[kind].email;
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
            }
        }
    };
}
