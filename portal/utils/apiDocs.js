/**
 * Swagger UI framed by the Administration > API sub-tab (/swagger/).
 *
 * A reverse proxy that sends X-Frame-Options: DENY (or frame-ancestors 'none') for
 * /swagger/ makes the browser replace the page by an error page of another origin: the
 * frame is then empty, and nothing in it is readable.
 */

/**
 * Whether a frame that has just loaded shows Swagger UI
 * @param {HTMLIFrameElement} frame - The frame of the API sub-tab
 * @returns {boolean|null} true: Swagger UI shown; false: blocked (or another page);
 *     null: still the empty page of a frame being created
 */
export function swaggerFramed(frame) {
    let doc;
    try {
        doc = frame.contentDocument;
    } catch (e) {
        // Error page of another origin
        return false;
    }
    if (!doc) {
        return false;
    }
    if (doc.URL === 'about:blank') {
        return null;
    }
    return doc.getElementById('swagger-ui') !== null;
}
