<?php

/**
 * autoload.php
 *
 * Carbure autoload file: registers the class loader, then loads the configuration.
 *
 * The classes are loaded on demand (PSR-4, see ClassLoader), without Composer (the API
 * has no dependency):
 *   Carbure\Lib\<Class>          => src/lib/<Class>.php
 *   Carbure\Lib\Import\<Class>   => src/lib/import/<Class>.php
 *   Carbure\Resources\<Class>    => src/resources/<Class>.php
 * The bank profiles of src/lib/import/banks are loaded by BankProfiles.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

use Carbure\Lib\ClassLoader;
use Carbure\Lib\Config;

require_once __DIR__ . '/lib/ClassLoader.php';

ClassLoader::register();

Config::load(getenv('APP_ENV') ?: 'prod');
