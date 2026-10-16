<?php

/**
 * MobileApp.php
 *
 * Versions of the mobile app (iOS) supported by this backend, published by GET /health:
 * an app older than MIN_VERSION asks its user to update it.
 *
 * Raise MIN_VERSION only when a change of the API cannot be read by the older apps
 * (AGENTS.md: the API stays backward compatible, so this should stay rare), and only
 * once the new version of the app is published on the App Store.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class MobileApp {

    /** Lowest version of the app supported (its MARKETING_VERSION, e.g. 1.0) */
    const MIN_VERSION = '1.0';
}
