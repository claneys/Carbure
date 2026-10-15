// Starts Swagger UI on the OpenAPI description of the API (swagger.php). A file rather
// than an inline script: the Content-Security-Policy of /swagger/ allows only 'self'.
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
        tryItOutEnabled: true
    });
};
