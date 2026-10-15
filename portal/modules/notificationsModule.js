/**
 * Notifications Module
 *
 * Notifications section of the profile page: for each kind of notification
 * (synchronization result, rule marked "notify", large expense), the channels it
 * is sent on (push to the iOS app, email to the address of the profile). Each
 * change is saved at once.
 *
 * @module notificationsModule
 */

/** Kinds of notification, in the order of the table */
export const NOTIFICATION_KINDS = ['sync', 'rule', 'expense'];

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
                notificationChannels: NOTIFICATION_CHANNELS
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
            }
        }
    };
}
