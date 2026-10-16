// Starts Swagger UI on the OpenAPI description of the API (swagger.php). A file rather
// than an inline script: the Content-Security-Policy of /swagger/ allows only 'self'.

/**
 * Root element of the portal when Swagger UI is framed by it (Administration > API).
 * nginx only lets the same origin frame /swagger/ (frame-ancestors 'self'): another
 * parent page is not readable, and Swagger UI is then shown on its own.
 * @returns {HTMLElement|null} The <html> of the portal, or null
 */
function carburePortalRoot() {
    try {
        if (window.parent !== window && window.parent.document) {
            return window.parent.document.documentElement;
        }
    } catch (e) {
        // Parent of another origin
    }
    return null;
}

/**
 * Dark theme of Swagger UI (class dark-mode of its <html>): the one displayed by the
 * portal when framed by it (and its changes), the preference of the system otherwise
 */
function carbureFollowTheme() {
    const html = document.documentElement;
    const portal = carburePortalRoot();
    if (portal) {
        const apply = () => html.classList.toggle('dark-mode', portal.getAttribute('data-theme') === 'dark');
        apply();
        new MutationObserver(apply).observe(portal, { attributes: true, attributeFilter: ['data-theme'] });
        return;
    }
    const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    if (media) {
        const apply = () => html.classList.toggle('dark-mode', media.matches);
        apply();
        media.addEventListener('change', apply);
    }
}

/**
 * Session of the portal (JWT kept by the portal in the storage of this origin), so that
 * "Try it out" calls the API as the logged-in user. The token stays in this page: it is
 * only sent in the Authorization header of the requests to /api, never in a URL.
 * @returns {string|null} The token, or null when nobody is logged in
 */
function carbureSessionToken() {
    try {
        return window.localStorage.getItem('authToken');
    } catch (e) {
        return null;
    }
}

carbureFollowTheme();

window.onload = function () {
    window.ui = SwaggerUIBundle({
        url: '/swagger/swagger.php',
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset
        ],
        plugins: [
            SwaggerUIBundle.plugins.DownloadUrl
        ],
        layout: 'StandaloneLayout',
        // No badge from validator.swagger.io: nothing is loaded from another site
        validatorUrl: null,
        defaultModelsExpandDepth: 1,
        defaultModelExpandDepth: 1,
        docExpansion: 'list',
        filter: true,
        tryItOutEnabled: true,
        // Authorized with the session of the portal (not kept by Swagger UI itself)
        onComplete: function () {
            const token = carbureSessionToken();
            if (token) {
                window.ui.preauthorizeApiKey('BearerAuth', token);
            }
        }
    });
};
