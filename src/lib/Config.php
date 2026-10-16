<?php

/**
 * Config.php
 *
 * A simple config loader.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Exception;

final class Config {

    /**
     * Static settings
     * @var array<string, mixed>
     */
    private static array $SETTINGS = [];

    /**
     * Get the config file path based on the environment.
     *
     * @param string $path The base path of the project
     * @param string $env  The environment (prod, dev, test)
     * @return string The config file path
     * @throws Exception If the config file is not found
     */
    private static function getConfigFile(string $path, string $env): string {

        // The configuration of the instance is in data/conf; conf/ only holds the
        // versioned templates (prod.sample.ini) and the configuration of the tests
        foreach (["$path/data/conf/$env.ini", "$path/conf/$env.ini"] as $file) {
            if (file_exists($file)) {
                return $file;
            }
        }
        throw new Exception("Config file not found: $path/data/conf/$env.ini");
    }

    /**
     * Load the configuration file.
     *
     * @param string $env The environment (prod, dev, test)
     * @return void
     * @throws Exception If the config file is not found or invalid
     */ 
    public static function load(string $env = "prod"): void {
        
        // Install directory is the root of the project
        $INSTALL_DIR = dirname(dirname(__DIR__));

        // Load the settings
        $configFile = self::getConfigFile($INSTALL_DIR,$env);
        $settings = parse_ini_file($configFile);
        if ($settings === false) {
            throw new Exception("Invalid config file: $configFile");
        }
        self::$SETTINGS = $settings;

        // Debug mode
        if (self::get('log_level') === 'debug') {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            error_reporting(E_ALL);
        }

        self::set('install_dir', $INSTALL_DIR);
        // Data of the instance: configuration, logs, cache, woob (banks)
        self::set('data_dir', $INSTALL_DIR . '/data');
        self::set('config_file', $configFile);
    }

    /**
     * Get a setting from the configuration.
     *
     * @param string $key The setting key
     * @return mixed The setting value
     * @throws Exception If the setting is not found
     */
    public static function get(string $key): mixed {
       if (array_key_exists($key, self::$SETTINGS)) {
           return self::$SETTINGS[$key];
       } else {
           throw new Exception("Setting not found: $key");
       }
    }

    /**
     * Check if a setting exists and is not empty.
     *
     * @param string $key The setting key
     * @return bool True if the setting is defined
     */
    public static function has(string $key): bool {
        return !empty(self::$SETTINGS[$key]);
    }

    /**
     * Set a setting in the configuration.
     *
     * @param string $key   The setting key
     * @param mixed  $value The setting value
     * @return void
     */
    public static function set(string $key, mixed $value): void {
        self::$SETTINGS[$key] = $value;
    }
    
    /**
     * Convert relative path to absolute path using install directory as base
     * @param string $path Path to convert (can be relative or absolute)
     * @return string Absolute path
     */
    public static function getAbsolutePath(string $path): string
    {
        // If already absolute (starts with / or drive letter on Windows), return as is
        if (substr($path, 0, 1) === '/' || preg_match('/^[a-zA-Z]:\\\\/', $path)) {
            return $path;
        }
        
        // Relative paths (e.g. conf/certs/AuthKey.p8) are in the data directory
        return self::get('data_dir') . '/' . ltrim($path, '/');
    }
}