/**
 * Display theme of the portal: light or dark, applied to <html data-theme> before the
 * first paint (no flash of the other theme). Loaded as a plain script in the <head>:
 * the choice cached in this browser is applied at once, then the one of the profile
 * (carbureTheme.apply) once the user is known.
 *
 * Choices: "system" (follows the light/dark preference of the system, and its changes),
 * "light" or "dark". The high contrast preferences of the system (prefers-contrast,
 * forced-colors) are handled by style.css alone.
 */
(function (root) {
    const CHOICES = ['system', 'light', 'dark'];
    const media = root.matchMedia ? root.matchMedia('(prefers-color-scheme: dark)') : null;
    let choice = 'system';
    // Called with the theme displayed each time it is applied (the header toggle)
    const listeners = [];

    /**
     * Theme to display for a choice
     * @param {string} value - system, light or dark
     * @param {boolean} systemDark - The system prefers a dark theme
     * @returns {string} light or dark
     */
    function resolve(value, systemDark) {
        if (value === 'light' || value === 'dark') {
            return value;
        }
        return systemDark ? 'dark' : 'light';
    }

    /**
     * Theme displayed by the page
     * @returns {string} light or dark
     */
    function current() {
        return resolve(choice, !!(media && media.matches));
    }

    /**
     * Theme to choose to switch the page to the other one (header toggle)
     * @returns {string} dark when the light theme is displayed, light otherwise
     */
    function opposite() {
        return current() === 'dark' ? 'light' : 'dark';
    }

    /**
     * Calls a function with the theme displayed, now and on each change
     * @param {Function} listener - Called with light or dark
     */
    function subscribe(listener) {
        listeners.push(listener);
        listener(current());
    }

    /** Applies the current choice to the page */
    function render() {
        const html = root.document.documentElement;
        const theme = current();
        html.dataset.theme = theme;
        html.dataset.themeChoice = choice;
        listeners.forEach(listener => listener(theme));
    }

    /**
     * Applies a choice and keeps it for the next visits of this browser
     * @param {string} value - system, light or dark (anything else: system)
     * @returns {string} The choice applied
     */
    function apply(value) {
        choice = CHOICES.includes(value) ? value : 'system';
        try {
            root.localStorage.setItem('theme', choice);
        } catch (e) {
            // Storage refused (private mode): the choice still applies to this page
        }
        render();
        return choice;
    }

    // "system" follows the changes of the system preference without reloading
    if (media && media.addEventListener) {
        media.addEventListener('change', () => {
            if (choice === 'system') {
                render();
            }
        });
    }

    let cached = null;
    try {
        cached = root.localStorage.getItem('theme');
    } catch (e) {
        cached = null;
    }
    apply(cached);

    root.carbureTheme = { apply, resolve, current, opposite, subscribe, choice: () => choice, CHOICES };
})(window);
