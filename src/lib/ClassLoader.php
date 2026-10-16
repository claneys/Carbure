<?php

/**
 * ClassLoader.php
 *
 * Loader of the classes of Carbure, on demand (PSR-4), without Composer: the API has
 * no dependency and the Docker image has no vendor/ directory.
 *
 *   Carbure\Lib\Config             => src/lib/Config.php
 *   Carbure\Lib\Import\CsvReader   => src/lib/import/CsvReader.php
 *   Carbure\Resources\User         => src/resources/User.php
 *
 * The class name is the file name; a sub-namespace (StudlyCase) is a sub-directory in
 * lowercase, as lib/ and resources/.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class ClassLoader {

    /**
     * Directory of the classes of each namespace
     */
    private const NAMESPACES = [
        'Carbure\\Lib\\' => __DIR__,
        'Carbure\\Resources\\' => __DIR__ . '/../resources',
    ];

    /**
     * Register the loader (once).
     *
     * @return void
     */
    public static function register(): void
    {
        static $registered = false;
        if (!$registered) {
            spl_autoload_register([self::class, 'load']);
            $registered = true;
        }
    }

    /**
     * Load a class of Carbure.
     *
     * @param string $class The fully qualified class name (e.g. Carbure\Resources\User)
     * @return void
     */
    public static function load(string $class): void
    {
        $file = self::fileOf($class);
        if ($file !== null && is_file($file)) {
            require_once $file;
        }
    }

    /**
     * File of a class of Carbure.
     *
     * @param string $class The fully qualified class name
     * @return string|null The file of the class, null if it is not a class of Carbure
     */
    public static function fileOf(string $class): ?string
    {
        foreach (self::NAMESPACES as $prefix => $dir) {
            // Sub-namespaces (StudlyCase, lowercase directories), then the class: nothing else
            if (str_starts_with($class, $prefix)
                    && preg_match('/^((?:[A-Z][A-Za-z0-9]*\\\\)*)([A-Za-z_][A-Za-z0-9_]*)$/', substr($class, strlen($prefix)), $m)) {
                return $dir . '/' . strtolower(str_replace('\\', '/', $m[1])) . $m[2] . '.php';
            }
        }
        return null;
    }
}
