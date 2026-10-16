<?php

/**
 * Setting.php
 *
 * Settings of the instance changed from the portal (table settings), as
 * opposed to the configuration file written at the installation.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class Setting {

    /**
     * Value of a setting.
     *
     * @param string $name    The setting
     * @param string $default Value when it is not set
     * @return string The value
     */
    public static function get(string $name, string $default = ''): string
    {
        $row = Db::queryOne("SELECT value FROM settings WHERE name = ?", "s", $name);
        return $row ? $row['value'] : $default;
    }

    /**
     * Change a setting.
     *
     * @param string $name  The setting
     * @param string $value The value
     * @return void
     */
    public static function set(string $name, string $value): void
    {
        Db::execute("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", "ss", $name, $value);
    }
}
