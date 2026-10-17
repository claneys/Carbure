<?php

/**
 * Settings.php
 *
 * Configuration file of the instance in the portal (administrators): every
 * setting is shown, the secrets masked; only the settings that cannot run a
 * command, break the database connection nor change the transaction UUIDs can
 * be changed, each one checked against a strict rule. Also the request that
 * starts the bank synchronization (for a scheduler) and its token, the test email,
 * and the registration on the push service (Carbure Push) with a test push.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Installer;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\Messages;
use Carbure\Lib\PushRelay;
use Error;
use Exception;

final class Settings {

    /** Settings whose value is never sent to the portal (apns_certificate_password: files
     *  older than the push service) */
    const SECRETS = ['db_password', 'jwtsecret', 'password_salt', 'sync_token', 'apns_certificate_password', 'smtp_password', 'push_api_key'];

    /**
     * Settings the portal can change: kind (select, bool, number, text, url, email,
     * password), options or limits, and for the texts the pattern of a valid value.
     * woob_logging and woob_transactions are put in the woob command line: only
     * known values are accepted. A password is a secret: never sent to the portal, only
     * replaced (no quote, backslash, dollar nor control character: they would change
     * its meaning in the ini file).
     */
    const EDITABLE = [
        'log_level' => ['kind' => 'select', 'options' => ['debug', 'info', 'warning', 'error']],
        'log_retention_days' => ['kind' => 'number', 'min' => 0, 'max' => 3650],
        'savings_category' => ['kind' => 'text', 'pattern' => '/^[\p{L}\p{N} \'\-]{1,50}$/u'],
        'public_url' => ['kind' => 'url', 'empty' => true],
        // Two-factor authentication (authenticator app) at the login of every user
        'mfa_required' => ['kind' => 'bool'],
        'woob_transactions' => ['kind' => 'number', 'min' => 1, 'max' => 5000],
        'woob_logging' => ['kind' => 'select', 'options' => ['debug', 'info', 'warning', 'error', 'critical']],
        'woob_debug' => ['kind' => 'bool'],
        'woob_auto_update' => ['kind' => 'bool'],
        'woob_2fa_wait' => ['kind' => 'number', 'min' => 0, 'max' => 300],
        'woob_2fa_retries' => ['kind' => 'number', 'min' => 0, 'max' => 5],
        // Push service (Carbure Push): empty push_url is the official one, empty
        // push_api_key: no push is sent
        'push_url' => ['kind' => 'url', 'empty' => true],
        'push_api_key' => ['kind' => 'password', 'pattern' => '/^cpk_[0-9a-f]{16}_[0-9a-f]{64}$/', 'empty' => true],
        // Empty smtp_host: no email is sent
        'smtp_host' => ['kind' => 'text', 'pattern' => '/^[A-Za-z0-9.\-]{1,253}$/', 'empty' => true],
        'smtp_port' => ['kind' => 'number', 'min' => 1, 'max' => 65535],
        'smtp_encryption' => ['kind' => 'select', 'options' => ['none', 'starttls', 'tls']],
        'smtp_auth' => ['kind' => 'select', 'options' => ['none', 'plain', 'login']],
        'smtp_username' => ['kind' => 'text', 'pattern' => '/^[\p{L}\p{N}._%+@\-]{1,200}$/u', 'empty' => true],
        'smtp_password' => ['kind' => 'password', 'pattern' => '/^[^\x00-\x1F\x7F"\\\\$]{1,200}$/u', 'empty' => true],
        'smtp_from' => ['kind' => 'email', 'empty' => true],
        'smtp_from_name' => ['kind' => 'text', 'pattern' => '/^[\p{L}\p{N} .,\'&()\-]{1,100}$/u', 'empty' => true],
    ];

    /** Section of the settings that the file may not have yet */
    const SECTIONS = [
        'log_level' => 'global', 'log_retention_days' => 'global', 'savings_category' => 'global', 'public_url' => 'global',
        'mfa_required' => 'auth',
        'woob_transactions' => 'woob', 'woob_logging' => 'woob', 'woob_debug' => 'woob', 'woob_auto_update' => 'woob',
        'woob_2fa_wait' => 'woob', 'woob_2fa_retries' => 'woob',
        'push_url' => 'push', 'push_api_key' => 'push',
        'smtp_host' => 'smtp', 'smtp_port' => 'smtp', 'smtp_encryption' => 'smtp', 'smtp_auth' => 'smtp',
        'smtp_username' => 'smtp', 'smtp_password' => 'smtp', 'smtp_from' => 'smtp', 'smtp_from_name' => 'smtp',
    ];

    /**
     * The configuration file, by section (administrators).
     *
     * @return array<string, mixed> file (path in the instance), writable, sections [{name, settings:
     *               [{key, value, set, secret, editable, kind, options?, min?, max?}]}]
     * @throws Exception If the file cannot be read
     */
    #[ApiRoute('/system/config', method: 'GET', admin: true)]
    public static function get(): array
    {
        $file = self::file();
        $ini = @parse_ini_file($file, true, INI_SCANNER_RAW);
        if ($ini === false) {
            throw new Exception("The configuration file cannot be read");
        }

        $sections = [];
        $seen = [];
        foreach ($ini as $name => $settings) {
            $list = [];
            foreach ((array)$settings as $key => $value) {
                $list[] = self::describe($key, $value);
                $seen[$key] = true;
            }
            $sections[$name] = $list;
        }
        // Editable settings missing from the file: shown as not set
        foreach (self::EDITABLE as $key => $rule) {
            if (!isset($seen[$key])) {
                $sections[self::SECTIONS[$key]][] = self::describe($key, null);
            }
        }

        $result = [];
        foreach ($sections as $name => $list) {
            $result[] = ['name' => $name, 'settings' => $list];
        }
        $root = Config::get('install_dir') . '/';
        return [
            'file' => str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file),
            'writable' => is_writable($file),
            'sections' => $result,
        ];
    }

    /**
     * Change settings of the configuration file (administrators). Every value is
     * checked before anything is written; the previous file is kept as <file>.bak.
     *
     * @param array<mixed> $values The new values, by key (editable settings only)
     * @return array<string, mixed> The configuration, as GET /system/config
     * @throws Error If a setting cannot be changed or a value is not valid (400)
     * @throws Exception If the file cannot be written
     */
    #[ApiRoute('/system/config', method: 'PUT', admin: true)]
    public static function update(array $values): array
    {
        if (!$values) {
            throw new Error("No setting to change", 400);
        }

        $checked = [];
        foreach ($values as $key => $value) {
            $checked[$key] = self::check((string)$key, $value);
        }

        $file = self::file();
        $ini = self::read($file);
        foreach ($checked as $key => $value) {
            // A password is always quoted: "yes", "none"... would be read as booleans
            $ini = Installer::setIni(self::addMissing($ini, $key), $key, $value, self::EDITABLE[$key]['kind'] === 'password');
        }
        self::write($file, $ini);
        Logger::warn("Configuration changed by user " . Jwt::getUserIdFromToken() . ": " . implode(', ', array_keys($checked)));

        return self::get();
    }

    /**
     * A setting missing from the file is added (empty) into its section, the section
     * added at the end of the file if needed (a file older than the setting).
     *
     * @param string $ini The INI content
     * @param string $key The setting (editable)
     * @return string The INI content
     */
    private static function addMissing(string $ini, string $key): string
    {
        if (preg_match('/^;?\s*' . preg_quote($key, '/') . '\s*=/m', $ini)) {
            return $ini;
        }
        $section = self::SECTIONS[$key];
        $header = '/^\[' . $section . '\][^\n]*(\n|$)/m';
        if (!preg_match($header, $ini)) {
            return rtrim($ini) . "\n\n[$section]\n$key=\n";
        }
        return preg_replace_callback($header, fn($match) => rtrim($match[0], "\n") . "\n$key=\n", $ini, 1) ?? $ini;
    }

    /**
     * The request that starts the bank synchronization, for a scheduler (administrators):
     * the token goes in the X-Sync-Token header (in the URL, it is deprecated).
     *
     * @param string|bool|null $reveal Return the token itself (masked by default): "true"...
     * @return array<string, mixed> url, token (null if there is none), masked, accounts (the bank
     *               accounts, for ?account=), header (the request header of the token)
     */
    #[ApiRoute('/system/sync', method: 'GET', admin: true)]
    public static function sync(string|bool|null $reveal = false): array
    {
        // From the file: the token may have been renewed since the configuration was loaded
        $ini = @parse_ini_file(self::file());
        $token = !empty($ini['sync_token']) ? (string)$ini['sync_token'] : null;
        $masked = !filter_var($reveal, FILTER_VALIDATE_BOOLEAN);
        $accounts = Db::execute("SELECT DISTINCT CONCAT(account_number, '@', bank_name) AS id FROM bank_account ORDER BY id", "")
            ->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'url' => OAuth::baseUrl() . '/api/bank/sync',
            'token' => $token === null ? null : ($masked ? str_repeat('•', 12) : $token),
            'masked' => $masked && $token !== null,
            'accounts' => array_column($accounts, 'id'),
            'header' => Bank::SYNC_TOKEN_HEADER,
        ];
    }

    /**
     * Replace the synchronization token by a new random one (administrators): the
     * scheduler must use the new one.
     *
     * @return array<string, string> token (the new one)
     * @throws Exception If the file cannot be written
     */
    #[ApiRoute('/system/sync-token', method: 'POST', admin: true)]
    public static function renewSyncToken(): array
    {
        $token = bin2hex(random_bytes(24));
        $file = self::file();
        self::write($file, Installer::setIni(self::read($file), 'sync_token', $token));
        Config::set('sync_token', $token);
        Logger::warn("sync_token renewed by user " . Jwt::getUserIdFromToken());
        return ['token' => $token];
    }

    /**
     * Send a test email with the SMTP server of the configuration file (administrators),
     * to the address of the administrator or to another one; in the language of the user
     * of that address, else of the administrator.
     *
     * @param string|null $to The recipient (optional, the address of the administrator by default)
     * @return array<string, mixed> sent (true), to (the recipient)
     * @throws Error If no SMTP server or no recipient (400), or the server refused the
     *               email (502, with its reply)
     */
    #[ApiRoute('/system/smtp-test', method: 'POST', admin: true)]
    public static function testEmail(?string $to = null): array
    {
        $user = Db::queryOne("SELECT email, language FROM users WHERE id = ?", "i", Jwt::getUserIdFromToken());
        $to = trim((string)($to ?: ($user['email'] ?? '')));
        if ($to === '') {
            throw new Error("No recipient: add an email address to your profile", 400);
        }

        $recipient = Db::queryOne("SELECT language FROM users WHERE email = ?", "s", $to);
        $language = (string)(($recipient ?? $user)['language'] ?? '');
        Notification::sendEmail($to, $language, Messages::t($language, 'smtp_test_title'), Messages::t($language, 'smtp_test_body'), false);
        Logger::info("Test email sent to $to by user " . Jwt::getUserIdFromToken());
        return ['sent' => true, 'to' => $to];
    }

    /**
     * Register the instance on the push service (Carbure Push) and save its API key in
     * the configuration file (administrators). With a key already, the key is renewed
     * instead: the devices keep receiving the notifications. The key is never returned.
     *
     * @return array<string, mixed> registered (true), renewed (a key was replaced), key_id
     *               (public id of the key), limits (of the push service)
     * @throws Error If the push service refuses or cannot be reached (502), or too many
     *               registrations (429)
     * @throws Exception If the configuration file cannot be written (checked first: a
     *                   key obtained must not be lost)
     */
    #[ApiRoute('/system/push-register', method: 'POST', admin: true)]
    public static function registerPush(): array
    {
        $file = self::file();
        $ini = self::read($file);
        if (!is_writable($file)) {
            throw new Exception("The configuration file cannot be written by the web server");
        }

        $renewed = PushRelay::isConfigured();
        $key = $renewed ? PushRelay::rotate() : PushRelay::register(self::instanceName());
        self::write($file, Installer::setIni(self::addMissing($ini, 'push_api_key'), 'push_api_key', $key['api_key'], true));
        Config::set('push_api_key', $key['api_key']);
        Logger::warn("Push service API key " . ($renewed ? 'renewed' : 'registered') . " by user " . Jwt::getUserIdFromToken() . ": {$key['key_id']}");

        return ['registered' => true, 'renewed' => $renewed, 'key_id' => $key['key_id'], 'limits' => $key['limits']];
    }

    /**
     * Send a test push notification to the devices of the administrator (administrators),
     * in his language.
     *
     * @return array<string, mixed> sent (devices reached), devices (devices of the
     *               administrator), results [{device (name), sent, status, error}]
     * @throws Error If there is no API key of the push service or no device (400)
     */
    #[ApiRoute('/system/push-test', method: 'POST', admin: true)]
    public static function testPush(): array
    {
        if (!PushRelay::isConfigured()) {
            throw new Error("No API key of the push service: register the instance first", 400);
        }
        $userId = Jwt::getUserIdFromToken();
        $devices = Device::get($userId);
        if (!$devices) {
            throw new Error("No device: log in to the iOS app with your account first", 400);
        }

        $language = (string)(Db::queryOne("SELECT language FROM users WHERE id = ?", "i", $userId)['language'] ?? '');
        $results = Device::sendNotification($userId, Messages::t($language, 'push_test_title'), Messages::t($language, 'push_test_body'));
        $names = array_column($devices, 'name', 'token');
        $list = [];
        foreach ($results as $token => $result) {
            $list[] = ['device' => (string)($names[$token] ?? ''), 'sent' => (bool)$result['success'],
                'status' => (string)($result['status'] ?? 'error'), 'error' => $result['error'] ?? null];
        }
        $sent = count(array_filter(array_column($list, 'sent')));
        Logger::info("Test push sent by user $userId to $sent of " . count($list) . " device(s)");
        return ['sent' => $sent, 'devices' => count($list), 'results' => $list];
    }

    /**
     * Name of the instance given to the push service: the host of its public URL.
     *
     * @return string
     */
    private static function instanceName(): string
    {
        $host = Config::has('public_url') ? parse_url((string)Config::get('public_url'), PHP_URL_HOST) : null;
        return is_string($host) && $host !== '' ? $host : 'Carbure';
    }

    /**
     * A setting as shown in the portal.
     *
     * @param string                     $key   The key
     * @param string|array<string>|null $value The raw value (null: not in the file)
     * @return array<string, mixed>
     */
    private static function describe(string $key, string|array|null $value): array
    {
        $rule = self::EDITABLE[$key] ?? null;
        $secret = in_array($key, self::SECRETS, true);
        $value = self::displayValue($rule, $value);
        $setting = [
            'key' => $key,
            'value' => $secret ? ($value === null || $value === '' ? '' : str_repeat('•', 12)) : $value,
            'set' => $value !== null && $value !== '',
            'secret' => $secret,
            'editable' => $rule !== null,
            'kind' => $rule['kind'] ?? (is_array($value) ? 'list' : 'text'),
        ];
        foreach (['options', 'min', 'max'] as $option) {
            if (isset($rule[$option])) {
                $setting[$option] = $rule[$option];
            }
        }
        return $setting;
    }

    /**
     * Value of a setting as shown in the portal: the lines of a list, a boolean for a
     * boolean setting, else the raw value.
     *
     * @param array<string, mixed>|null  $rule  The rule of the setting (null: not editable)
     * @param string|array<string>|null $value The raw value (null: not in the file)
     * @return string|bool|list<string>|null
     */
    private static function displayValue(?array $rule, string|array|null $value): string|bool|array|null
    {
        if (is_array($value)) {
            // regex_label[...]: the pattern and its replacement
            return array_map(fn($k, $v) => "$k => \"$v\"", array_keys($value), $value);
        }
        if ($value !== null && $rule && $rule['kind'] === 'bool') {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }
        return $value;
    }

    /**
     * Check a new value against the rule of its setting.
     *
     * @param string $key   The key
     * @param mixed  $value The value
     * @return string The value to write
     * @throws Error If the setting cannot be changed or the value is not valid (400)
     */
    private static function check(string $key, mixed $value): string
    {
        $rule = self::EDITABLE[$key] ?? null;
        if ($rule === null) {
            throw new Error("The setting $key cannot be changed from the portal", 400);
        }
        if (is_array($value) || is_object($value)) {
            throw new Error("Invalid value for $key", 400);
        }
        $value = trim((string)$value);
        if (!self::isValid($rule, $value)) {
            throw new Error("Invalid value for $key", 400);
        }
        if ($rule['kind'] === 'bool') {
            return in_array($value, ['1', 'true'], true) ? 'true' : 'false';
        }
        return $rule['kind'] === 'url' ? rtrim($value, '/') : $value;
    }

    /**
     * Whether a value (trimmed) follows the rule of its setting.
     *
     * @param array<string, mixed> $rule  The rule of the setting
     * @param string               $value The value
     * @return bool
     */
    private static function isValid(array $rule, string $value): bool
    {
        // An empty value is accepted by some settings (unset)
        $allowEmpty = !empty($rule['empty']);
        return match ($rule['kind']) {
            'select' => in_array($value, $rule['options'], true),
            'bool' => in_array($value, ['1', '0', 'true', 'false', ''], true),
            'number' => self::inRange($value, $rule['min'], $rule['max']),
            'url' => self::matches($value, '#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~/\-]*)?$#', $allowEmpty),
            'email' => ($value === '' && $allowEmpty) || (strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false),
            default => self::matches($value, $rule['pattern'], $allowEmpty),
        };
    }

    /**
     * Whether a value is a whole number between two bounds.
     *
     * @param string $value The value
     * @param int    $min   The lowest number allowed
     * @param int    $max   The highest number allowed
     * @return bool
     */
    private static function inRange(string $value, int $min, int $max): bool
    {
        return ctype_digit($value) && (int)$value >= $min && (int)$value <= $max;
    }

    /**
     * Whether a value matches a pattern, or is empty when allowed.
     *
     * @param string $value      The value
     * @param string $pattern    The regular expression
     * @param bool   $allowEmpty An empty value is accepted
     * @return bool
     */
    private static function matches(string $value, string $pattern, bool $allowEmpty): bool
    {
        return ($value === '' && $allowEmpty) || preg_match($pattern, $value) === 1;
    }

    /**
     * The configuration file (tests use a copy, see System::$configFile).
     *
     * @return string
     */
    private static function file(): string
    {
        return System::$configFile ?? Config::get('config_file');
    }

    /**
     * Content of the configuration file.
     *
     * @param string $file The file
     * @return string
     * @throws Exception If it cannot be read
     */
    private static function read(string $file): string
    {
        $ini = @file_get_contents($file);
        if ($ini === false) {
            throw new Exception("The configuration file cannot be read");
        }
        return $ini;
    }

    /**
     * Write the configuration file, the previous one kept as <file>.bak.
     *
     * @param string $file The file
     * @param string $ini  The new content
     * @return void
     * @throws Exception If it cannot be written, or the new content is not valid INI
     */
    private static function write(string $file, string $ini): void
    {
        if (@parse_ini_string($ini, true, INI_SCANNER_RAW) === false) {
            throw new Exception("The new configuration would not be valid");
        }
        if (!is_writable($file)) {
            throw new Exception("The configuration file cannot be written by the web server");
        }
        @copy($file, "$file.bak");
        if (@file_put_contents($file, $ini, LOCK_EX) === false) {
            throw new Exception("The configuration file cannot be written by the web server");
        }
    }
}
