<?php

/**
 * InstallCommand.php
 *
 * Installation from the command line (tools/install.php), without the web wizard: for
 * the scripted installations outside Docker (YunoHost package...). It goes through the
 * same code as the wizard (Setup, POST /setup/install): the schema is created or
 * migrated, the first administrator created and data/conf/<env>.ini written.
 *
 * The secrets come from the environment, never from the command line (visible to the
 * other users of the machine in the list of the processes): CARBURE_DB_PASSWORD and
 * CARBURE_ADMIN_PASSWORD.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class InstallCommand {

    /** Exit codes */
    const DONE = 0;
    const FAILED = 1;
    const ALREADY_INSTALLED = 2;

    /** Options of the command (getopt), each one with a value but the flags */
    const OPTIONS = ['db-host:', 'db-port:', 'db-name:', 'db-user:', 'admin:', 'email:', 'language:',
                     'woob-path:', 'public-url:', 'starter', 'backup-confirmed', 'help'];

    /** A public address, as Settings accepts it (Administration > Settings) */
    const PUBLIC_URL = '#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~/\-]*)?$#';

    /** Shortest administrator password, as the wizard (Installer::createAdmin) */
    const MIN_PASSWORD = 8;

    /** Options without which nothing is installed */
    const REQUIRED = ['db-name', 'db-user', 'admin'];

    /**
     * Install Carbure.
     *
     * @param array<string, string|false|list<string|false>> $options    The options (getopt with OPTIONS)
     * @param array<string, string>                          $env        The environment (CARBURE_DB_PASSWORD, CARBURE_ADMIN_PASSWORD)
     * @param string                                         $root       Root of the project
     * @param string                                         $configFile Configuration file to write (data/conf/<env>.ini)
     * @return array{0: int, 1: string} [exit code, message]
     */
    public static function run(array $options, array $env, string $root, string $configFile): array
    {
        if (is_file($configFile)) {
            return [self::ALREADY_INSTALLED, "Carbure is already installed ($configFile exists): nothing changed"];
        }
        $error = self::check($options, $env);
        if ($error !== null) {
            return [self::FAILED, $error];
        }
        $publicUrl = rtrim(self::option($options, 'public-url'), '/');

        // Read by Setup when it writes the configuration
        $woobPath = self::option($options, 'woob-path');
        if ($woobPath !== '') {
            putenv("CARBURE_WOOB_PATH=$woobPath");
        }
        $codeFile = dirname($configFile) . '/setup.code';
        $setup = new Setup($root, $configFile, $codeFile);
        [$status, $body] = $setup->handle('POST', '/setup/install', self::fields($options, $env, $codeFile));

        if ($status !== 200) {
            return [self::FAILED, "Installation failed: " . ($body['error'] ?? "HTTP $status")];
        }
        if ($publicUrl !== '') {
            file_put_contents($configFile, Installer::setIni(File::read($configFile), 'public_url', $publicUrl));
        }
        return [self::DONE, "Carbure installed: schema " . ($body['version'] ?? 'none')
            . ($body['username'] ? ", administrator " . $body['username'] : ", administrator already there")];
    }

    /**
     * Checks done before anything is installed.
     *
     * @param array<string, string|false|list<string|false>> $options The options
     * @param array<string, string>                          $env     The environment
     * @return string|null The error, null when the installation can go on
     */
    private static function check(array $options, array $env): ?string
    {
        $missing = array_values(array_filter(self::REQUIRED, fn(string $name): bool => self::option($options, $name) === ''));
        if ($missing) {
            return "Missing option: --" . implode(', --', $missing);
        }
        $password = $env['CARBURE_ADMIN_PASSWORD'] ?? '';
        if ($password === '') {
            return "Missing administrator password: set CARBURE_ADMIN_PASSWORD";
        }
        // Checked by the wizard too, but after the connection to the database
        if (strlen($password) < self::MIN_PASSWORD) {
            return "The administrator password must contain at least " . self::MIN_PASSWORD . " characters";
        }
        $publicUrl = rtrim(self::option($options, 'public-url'), '/');
        if ($publicUrl !== '' && !preg_match(self::PUBLIC_URL, $publicUrl)) {
            return "Invalid public address (--public-url): an http(s) URL such as https://carbure.example.fr";
        }
        return null;
    }

    /**
     * Fields of the request of the wizard.
     *
     * @param array<string, string|false|list<string|false>> $options  The options
     * @param array<string, string>                          $env      The environment
     * @param string                                         $codeFile File of the setup code
     * @return array<string, mixed>
     */
    private static function fields(array $options, array $env, string $codeFile): array
    {
        $starter = array_key_exists('starter', $options);
        return [
            'db_host' => self::option($options, 'db-host') ?: 'localhost',
            'db_port' => self::option($options, 'db-port') ?: '3306',
            'db_name' => self::option($options, 'db-name'),
            'db_user' => self::option($options, 'db-user'),
            'db_password' => $env['CARBURE_DB_PASSWORD'] ?? '',
            'admin_user' => self::option($options, 'admin'),
            'admin_password' => $env['CARBURE_ADMIN_PASSWORD'],
            'admin_email' => self::option($options, 'email'),
            'language' => self::option($options, 'language') ?: 'fr',
            'starter_categories' => $starter,
            'starter_insights' => $starter,
            'backup_confirmed' => array_key_exists('backup-confirmed', $options),
            // Needed when the wizard was opened from Internet before (data/conf/setup.code)
            'code' => is_file($codeFile) ? trim(File::read($codeFile)) : '',
        ];
    }

    /**
     * Value of an option: the last one when it is given twice, "" when it is missing.
     *
     * @param array<string, string|false|list<string|false>> $options The options
     * @param string                                         $name    The option
     * @return string
     */
    private static function option(array $options, string $name): string
    {
        $value = $options[$name] ?? '';
        if (is_array($value)) {
            $value = end($value);
        }
        return is_string($value) ? trim($value) : '';
    }
}
