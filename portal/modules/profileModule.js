/**
 * Profile Module
 * 
 * Manages the authenticated user: header user menu and profile page, in four
 * sub-tabs: profile (email, names, password, interface language and display
 * theme), two-factor authentication (mfaModule), notifications (notificationsModule)
 * and devices (mobile apps, web browsers and trusted web browsers).
 * 
 * @module profileModule
 */

import { qrCodeOf } from './mfaModule.js';

/** Page of carbure.org that opens the mobile apps on a server (Universal Links, App Links) */
export const APP_LINK_URL = 'https://carbure.org/app/';

/**
 * Link that opens the mobile apps on a server, for the QR code of the devices
 * @param {string} apiUrl - Address of the API (a trailing slash is added)
 * @returns {string} https://carbure.org/app/?server=<API URL, encoded>
 */
export function appLinkOf(apiUrl) {
    const server = String(apiUrl || '').replace(/\/*$/, '/');
    return `${APP_LINK_URL}?server=${encodeURIComponent(server)}`;
}

/**
 * Whether a phone cannot reach an address: localhost, 127.0.0.0/8, ::1
 * @param {string} url - Address
 * @returns {boolean}
 */
export function isLoopbackUrl(url) {
    let host;
    try {
        host = new URL(url).hostname.toLowerCase();
    } catch (error) {
        return false;
    }
    return host === 'localhost' || host.endsWith('.localhost') || /^127\./.test(host) || host === '[::1]';
}

/** Sub-tabs of the profile page, in their order */
export const PROFILE_TABS = [
    { key: 'profile', icon: 'person', label: 'profileTabProfile' },
    { key: 'security', icon: 'lock', label: 'mfaTitle' },
    { key: 'notifications', icon: 'notifications', label: 'notificationsTitle' },
    { key: 'devices', icon: 'smartphone', label: 'myDevices' }
];

/**
 * Applies the display theme of the profile (theme.js, loaded in the <head>)
 * @param {string} theme - system, light or dark
 */
function applyTheme(theme) {
    if (theme && typeof window !== 'undefined' && window.carbureTheme) {
        window.carbureTheme.apply(theme);
    }
}

/**
 * Creates a profile module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with profile methods and data
 */
export function createProfileModule(getApiService) {
    return {
        data() {
            return {
                currentUser: null,
                showUserMenu: false,
                savingProfile: false,
                // Sub-tab of the profile page shown
                profileTabs: PROFILE_TABS,
                profileView: PROFILE_TABS[0].key,
                devices: [],
                // Web browsers of the user: [{id, label, created_at, last_login, current}]
                browsers: [],
                loadingBrowsers: false,
                // Public address of the API (setting public_url), null: the one of the portal
                appServer: null,
                // Database migrations to apply (administrators): {version, pending}
                schemaStatus: null,
                migrating: false,
                rotatingSecret: false,
                apiTokens: [],
                // Token just created: {name, token}, shown once
                newApiToken: null,
                showApiTokenModal: false,
                apiTokenName: '',
                // Lifetime of a new token in days (0: never expires)
                apiTokenDays: 90,
                loadingDevices: false,
                revealedTokens: {},
                // Theme displayed by the page (light or dark), followed for the header toggle
                displayedTheme: 'light',
                savingTheme: false,
                profileForm: {
                    email: '',
                    firstname: '',
                    lastname: '',
                    password: '',
                    language: 'fr',
                    theme: 'system'
                }
            };
        },

        computed: {
            /**
             * Address of the API given to the mobile apps: the public one (public_url),
             * else the one of the portal
             * @returns {string}
             */
            appServerUrl() {
                return this.appServer || this.apiBaseUrl || '';
            },

            /**
             * Link of the QR code that opens the mobile apps on this server
             * @returns {string}
             */
            appLink() {
                return appLinkOf(this.appServerUrl);
            },

            /**
             * QR code of the link of the mobile apps
             * @returns {{size: number, path: string}|null} null if the library is missing
             */
            appQr() {
                return qrCodeOf(this.appLink);
            },

            /**
             * The address given to the apps is not reachable by a phone (localhost)
             * @returns {boolean}
             */
            appServerUnreachable() {
                return isLoopbackUrl(this.appServerUrl);
            },

            /**
             * Sub-tabs of the profile page shown: the two-factor authentication only once
             * its migration is applied
             * @returns {Array<{key: string, icon: string, label: string}>}
             */
            visibleProfileTabs() {
                const mfaUnavailable = this.mfaStatus && !this.mfaStatus.available;
                return this.profileTabs.filter(tab => tab.key !== 'security' || !mfaUnavailable);
            },

            /**
             * Checks if the authenticated user is an administrator
             * @returns {boolean}
             */
            isAdmin() {
                return !!(this.currentUser && Number(this.currentUser.is_admin));
            },

            /**
             * Action of the header theme toggle, its accessible name and tooltip
             * @returns {string}
             */
            themeToggleLabel() {
                return this.t(this.displayedTheme === 'dark' ? 'switchToLightTheme' : 'switchToDarkTheme');
            },

            /**
             * Icon of the header theme toggle: the theme it switches to
             * @returns {string} Material icon name
             */
            themeToggleIcon() {
                return this.displayedTheme === 'dark' ? 'light_mode' : 'dark_mode';
            }
        },

        mounted() {
            // The theme displayed changes with the profile, the toggle and the system
            if (typeof window !== 'undefined' && window.carbureTheme) {
                window.carbureTheme.subscribe(theme => {
                    this.displayedTheme = theme;
                });
            }
        },

        methods: {
            /**
             * Loads the authenticated user and applies his language
             * @returns {Promise<void>}
             */
            async loadCurrentUser() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }

                try {
                    this.currentUser = await apiService.fetchMe();

                    // The theme of the profile wins over the one cached in this browser
                    applyTheme(this.currentUser.theme);

                    // The language of the profile wins over the browser one
                    const language = this.currentUser.language;
                    if (language && language !== this.locale) {
                        this.changeLocale(language);
                    }

                    if (Number(this.currentUser.is_admin)) {
                        this.loadSchemaStatus();
                    }
                } catch (error) {
                    console.error('Error loading current user:', error);
                    // The profile may fail because of a pending migration of the database:
                    // the banner that applies it must still be offered (the route is
                    // reserved to administrators, the others get no banner)
                    this.loadSchemaStatus();
                }
            },

            /**
             * Loads the version of the database schema and the migrations to apply
             * @returns {Promise<void>}
             */
            async loadSchemaStatus() {
                try {
                    this.schemaStatus = await getApiService().fetchSchemaStatus();
                } catch (error) {
                    this.schemaStatus = null;
                }
            },

            /**
             * Applies the pending migrations of the database
             * @returns {Promise<void>}
             */
            async applyMigrations() {
                if (!confirm(this.t('confirmMigrate'))) {
                    return;
                }
                this.migrating = true;
                try {
                    const result = await getApiService().migrateSchema();
                    this.schemaStatus = { version: result.version, pending: [] };
                    this.showToast(this.t('migrationDone', { version: result.version }));
                    this.refreshCurrentTab();
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.migrating = false;
                }
            },

            /**
             * Renews the secret of the sessions: everybody logs in again
             * @returns {Promise<void>}
             */
            async rotateJwtSecret() {
                if (!confirm(this.t('confirmRotateJwtSecret'))) {
                    return;
                }
                this.rotatingSecret = true;
                try {
                    await getApiService().rotateJwtSecret();
                    this.logout();
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.rotatingSecret = false;
                }
            },

            /**
             * Switches to the other theme (header toggle): light when the dark one is
             * displayed, dark otherwise. Applied at once, then saved in the profile like
             * the choice of the profile page (it follows the user on another browser);
             * the profile keeps "Same as the system" to follow the system again.
             * @returns {Promise<void>}
             */
            async toggleTheme() {
                const themes = typeof window !== 'undefined' ? window.carbureTheme : null;
                if (!themes || this.savingTheme) {
                    return;
                }
                const previous = themes.choice();
                const next = themes.opposite();
                themes.apply(next);
                this.profileForm.theme = next;
                if (!this.currentUser) {
                    return;
                }

                this.savingTheme = true;
                try {
                    const user = await getApiService().updateUserTheme(this.currentUser.id, next);
                    this.currentUser = { ...this.currentUser, theme: user.theme };
                } catch (error) {
                    // Not saved: back to the previous choice
                    themes.apply(previous);
                    this.profileForm.theme = previous;
                    this.showToast(error.message || this.t('errorSavingUser'));
                } finally {
                    this.savingTheme = false;
                }
            },

            /**
             * Toggles the header user menu
             */
            toggleUserMenu() {
                this.showUserMenu = !this.showUserMenu;
            },

            /**
             * Closes the header user menu
             */
            closeUserMenu() {
                this.showUserMenu = false;
            },

            /**
             * Opens the profile page with the current user data
             * @returns {Promise<void>}
             */
            async openProfile() {
                this.closeUserMenu();

                // The profile may still be loading (slow network): wait for it
                if (!this.currentUser) {
                    await this.loadCurrentUser();
                }

                const user = this.currentUser || {};
                this.profileForm = {
                    email: user.email || '',
                    firstname: user.firstname || '',
                    lastname: user.lastname || '',
                    password: '',
                    language: user.language || this.locale,
                    theme: user.theme || 'system'
                };
                this.profileView = PROFILE_TABS[0].key;
                this.setActiveTab('profile');
                this.loadDevices();
                this.loadBrowsers();
                this.loadAppServer();
                this.loadNotifications();
                this.loadMfaStatus();
            },

            /**
             * Platform of a device: icon and translation key of its name
             * @param {Object} device - Device ({platform: ios|android}, ios when absent)
             * @returns {{icon: string, label: string}}
             */
            devicePlatform(device) {
                return device && device.platform === 'android'
                    ? { icon: 'android', label: 'devicePlatform_android' }
                    : { icon: 'phone_iphone', label: 'devicePlatform_ios' };
            },

            /**
             * Model, system version and app version of a device, those sent by the app
             * (e.g. "iPhone16,1 · iOS 26.0 · app 1.2"); empty when none is known
             * @param {Object} device - Device ({model, os_version, app_version}, null or absent when unknown)
             * @returns {string}
             */
            deviceDetails(device) {
                const text = value => (value === null || value === undefined ? '' : String(value).trim());
                const parts = [text(device && device.model), text(device && device.os_version)];
                const app = text(device && device.app_version);
                if (app) {
                    parts.push(this.t('deviceAppVersion', { version: app }));
                }
                return parts.filter(Boolean).join(' · ');
            },

            /**
             * Loads the devices (mobile app installations: iOS, Android) of the authenticated user
             * @returns {Promise<void>}
             */
            async loadDevices() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }

                this.loadingDevices = true;
                this.revealedTokens = {};
                try {
                    this.devices = await apiService.fetchDevices();
                } catch (error) {
                    console.error('Error loading devices:', error);
                    this.devices = [];
                    this.showToast(error.message);
                } finally {
                    this.loadingDevices = false;
                }
            },

            /**
             * Loads the API tokens (MCP server for Claude) of the authenticated user
             * @returns {Promise<void>}
             */
            async loadApiTokens() {
                try {
                    this.apiTokens = await getApiService().fetchApiTokens();
                } catch (error) {
                    this.apiTokens = [];
                }
            },

            /**
             * Opens the modal to create an API token
             */
            openApiTokenModal() {
                this.apiTokenName = '';
                this.apiTokenDays = 90;
                this.selectedAgent = 'claude-code';
                this.newApiToken = null;
                this.showApiTokenModal = true;
                this.$nextTick(() => document.getElementById('api-token-name')?.select());
            },

            /**
             * Closes the API token modal (the new token can no longer be displayed)
             */
            closeApiTokenModal() {
                this.showApiTokenModal = false;
                this.newApiToken = null;
            },

            /**
             * Creates an API token and shows it once
             * @returns {Promise<void>}
             */
            async createApiToken() {
                const name = this.apiTokenName.trim();
                if (!name) {
                    this.showToast(this.t('apiTokenNameRequired'));
                    return;
                }
                try {
                    this.newApiToken = await getApiService().createApiToken(name, this.apiTokenDays);
                    await this.loadApiTokens();
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * URL of the MCP server
             * @returns {string}
             */
            mcpUrl() {
                return new URL(`${this.apiBaseUrl.replace(/\/$/, '')}/mcp`, window.location.href).href;
            },

            /**
             * Copies a text to the clipboard
             * @param {string} text - Text to copy
             * @returns {Promise<void>}
             */
            async copyText(text) {
                try {
                    await navigator.clipboard.writeText(text);
                    this.showToast(this.t('copied'));
                } catch (error) {
                    this.showToast(this.t('copyUnavailable'));
                }
            },

            /**
             * Revokes an API token after confirmation
             * @param {Object} apiToken - Token
             * @returns {Promise<void>}
             */
            async revokeApiToken(apiToken) {
                if (!confirm(this.t('confirmRevokeApiToken', { name: apiToken.name }))) {
                    return;
                }
                try {
                    await getApiService().deleteApiToken(apiToken.id);
                    this.apiTokens = this.apiTokens.filter(t => t.id !== apiToken.id);
                    this.showToast(this.t('apiTokenRevoked'));
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Returns the token to display: full if revealed, shortened otherwise
             * @param {Object} device - Device
             * @returns {string}
             */
            displayToken(device) {
                const token = device.token || '';
                if (this.revealedTokens[device.id] || token.length <= 12) {
                    return token;
                }
                return `${token.slice(0, 6)}…${token.slice(-6)}`;
            },

            /**
             * Shows or hides the full token of a device
             * @param {Object} device - Device
             */
            toggleToken(device) {
                this.revealedTokens = { ...this.revealedTokens, [device.id]: !this.revealedTokens[device.id] };
            },

            /**
             * Loads the public address of the API for the QR code of the mobile apps
             * (null on failure: the address of the portal is used)
             * @returns {Promise<void>}
             */
            async loadAppServer() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }
                try {
                    this.appServer = (await apiService.fetchDeviceLink()).server || null;
                } catch (error) {
                    console.error('Error loading the address of the apps:', error);
                    this.appServer = null;
                }
            },

            /**
             * Copies the link of the mobile apps to the clipboard
             * @returns {Promise<void>}
             */
            async copyAppLink() {
                try {
                    await navigator.clipboard.writeText(this.appLink);
                    this.showToast(this.t('appLinkCopied'));
                } catch (error) {
                    // Clipboard API needs HTTPS (or localhost): the link stays shown
                    this.showToast(this.t('copyUnavailable'));
                }
            },

            /**
             * Loads the web browsers from which the authenticated user logged in
             * @returns {Promise<void>}
             */
            async loadBrowsers() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }

                this.loadingBrowsers = true;
                try {
                    this.browsers = await apiService.fetchBrowsers();
                } catch (error) {
                    console.error('Error loading browsers:', error);
                    this.browsers = [];
                    this.showToast(error.message);
                } finally {
                    this.loadingBrowsers = false;
                }
            },

            /**
             * Forgets a web browser after confirmation: listed again at its next login, its
             * session is not closed
             * @param {Object} browser - Browser ({id, label})
             * @returns {Promise<void>}
             */
            async removeBrowser(browser) {
                const removed = await this.askConfirm({
                    title: this.t('deleteBrowser'),
                    message: this.t('confirmDeleteBrowser', { name: this.browserName(browser.label) }),
                    confirmLabel: this.t('deleteBrowser'),
                    icon: 'delete',
                    danger: true,
                    action: () => getApiService().deleteBrowser(browser.id)
                });
                if (!removed) {
                    return;
                }
                this.browsers = this.browsers.filter(b => b.id !== browser.id);
                this.showToast(this.t('browserDeleted'));
            },

            /**
             * Deletes a device after confirmation
             * @param {Object} device - Device
             * @returns {Promise<void>}
             */
            async removeDevice(device) {
                if (!confirm(this.t('confirmDeleteDevice', { name: device.name || this.t('unnamedDevice') }))) {
                    return;
                }

                try {
                    await getApiService().deleteDevice(device.id);
                    this.devices = this.devices.filter(d => d.id !== device.id);
                    this.showToast(this.t('deviceDeleted'));
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Copies the token of a device to the clipboard
             * @param {Object} device - Device
             * @returns {Promise<void>}
             */
            async copyToken(device) {
                try {
                    await navigator.clipboard.writeText(device.token);
                    this.showToast(this.t('tokenCopied'));
                } catch (error) {
                    // Clipboard API needs HTTPS (or localhost): reveal the token instead
                    this.revealedTokens = { ...this.revealedTokens, [device.id]: true };
                    this.showToast(this.t('copyUnavailable'));
                }
            },

            /**
             * Saves the profile of the authenticated user
             * @returns {Promise<void>}
             */
            async saveProfile() {
                const apiService = getApiService();
                if (!apiService || !this.currentUser) {
                    return;
                }

                if (this.profileForm.password && this.profileForm.password.length < 6) {
                    this.showToast(this.t('passwordTooShort'));
                    return;
                }

                this.savingProfile = true;
                try {
                    this.currentUser = await apiService.updateUser(
                        this.currentUser.id,
                        this.profileForm.email,
                        this.profileForm.firstname,
                        this.profileForm.lastname,
                        this.profileForm.password,
                        this.profileForm.language,
                        undefined,
                        undefined,
                        this.profileForm.theme
                    );
                    this.profileForm.password = '';
                    applyTheme(this.currentUser.theme);
                    // The email channel depends on the address of the profile
                    this.loadNotifications();

                    if (this.currentUser.language !== this.locale) {
                        // Reloads the page in the new language
                        this.changeLocale(this.currentUser.language);
                        return;
                    }

                    this.showToast(this.t('profileUpdated'));
                } catch (error) {
                    console.error('Error saving profile:', error);
                    this.showToast(error.message || this.t('errorSavingUser'));
                } finally {
                    this.savingProfile = false;
                }
            }
        }
    };
}
