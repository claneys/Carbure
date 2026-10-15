<?php

/**
 * autoload.php
 *
 * Carbure autoload file
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

// Load all the PHP files in the lib directory
foreach (glob(__DIR__ . '/lib/*.php') as $filename) {
    require_once $filename;
}

// Readers of the statement files (the bank profiles of import/banks are loaded by BankProfiles)
foreach (glob(__DIR__ . '/lib/import/*.php') as $filename) {
    require_once $filename;
}

// Load all resource classes
foreach (glob(__DIR__ . '/resources/*.php') as $filename) {
    require_once $filename;
}

Config::load(getenv('APP_ENV') ?: 'prod');