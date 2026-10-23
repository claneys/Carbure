<?php

/**
 * ReleaseCheck.php
 *
 * Whether a newer release of Carbure is published, for the banner of the administrators.
 * The server asks the public API of GitHub for the latest release (the CSP of the portal
 * allows no external request), at most once a day, and keeps the answer in the settings
 * table: nothing about the instance is sent. The setting update_check (on when it is not
 * set) turns it off; a development version (VERSION "dev") is never compared.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Exception;

final class ReleaseCheck {

    /** Latest release of Carbure (the tests point CARBURE_RELEASE_URL to their fake answer) */
    public const URL = 'https://api.github.com/repos/ltoinel/Carbure/releases/latest';

    /** Environment variable replacing URL in the tests */
    public const TEST_URL_VARIABLE = 'CARBURE_RELEASE_URL';

    /** Seconds between two checks */
    public const INTERVAL = 86400;

    /** Version of a release: X.Y.Z */
    private const VERSION = '/^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}$/';

    /**
     * The latest release known and whether it is newer than this version; asks GitHub
     * first when the last check is a day old.
     *
     * @param string        $current The version of this instance
     * @param callable|null $fetch   Answer of GitHub, decoded (null: failed); the HTTP call by default
     * @param int|null      $now     Current time (tests)
     * @return array{enabled: bool, current: string, latest: string|null, url: string|null, available: bool, checked_at: string|null}
     */
    public static function status(string $current, ?callable $fetch = null, ?int $now = null): array
    {
        $now ??= time();
        $enabled = self::enabled();
        if ($enabled && preg_match(self::VERSION, $current) && self::due($now)) {
            self::refresh($fetch ?? [self::class, 'fetch'], $now);
        }
        $latest = Setting::get('release_latest');
        $url = Setting::get('release_url');
        $checked = Setting::get('release_checked_at');
        return [
            'enabled' => $enabled,
            'current' => $current,
            'latest' => $latest === '' ? null : $latest,
            'url' => $url === '' ? null : $url,
            'available' => $enabled && self::isNewer($current, $latest),
            'checked_at' => $checked === '' ? null : $checked,
        ];
    }

    /**
     * Whether the check is on: the setting update_check, on when it is not set.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        // Not Config::has(): it takes false ("" in the ini file) for an unset setting
        try {
            $value = Config::get('update_check');
        } catch (Exception $e) {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether a release is newer than this version (never for a development version).
     *
     * @param string $current The version of this instance
     * @param string $latest  The version of the release ('' if none is known)
     * @return bool
     */
    public static function isNewer(string $current, string $latest): bool
    {
        return preg_match(self::VERSION, $current) === 1 && preg_match(self::VERSION, $latest) === 1
            && version_compare($latest, $current, '>');
    }

    /**
     * The release in an answer of GitHub: its version and the page of its notes.
     *
     * @param mixed $answer The answer, decoded
     * @return array{version: string, url: string}|null Null for anything else than a vX.Y.Z release
     */
    public static function parse(mixed $answer): ?array
    {
        $tag = is_array($answer) && is_string($answer['tag_name'] ?? null) ? $answer['tag_name'] : '';
        $version = substr($tag, 1);
        if (!str_starts_with($tag, 'v') || !preg_match(self::VERSION, $version)) {
            return null;
        }
        // The page of the notes, built from the version: never a link taken from the answer
        return ['version' => $version, 'url' => "https://github.com/ltoinel/Carbure/releases/tag/$tag"];
    }

    /**
     * Whether the last check is a day old (or there was none).
     *
     * @param int $now Current time
     * @return bool
     */
    private static function due(int $now): bool
    {
        $checked = strtotime(Setting::get('release_checked_at'));
        return $checked === false || $now - $checked >= self::INTERVAL;
    }

    /**
     * Ask for the latest release and keep it; a failure keeps the previous one and waits
     * for the next day too.
     *
     * @param callable $fetch Answer of GitHub, decoded (null: failed)
     * @param int      $now   Current time
     * @return void
     */
    private static function refresh(callable $fetch, int $now): void
    {
        Setting::set('release_checked_at', date('Y-m-d H:i:s', $now));
        $release = self::parse($fetch());
        if ($release !== null) {
            Setting::set('release_latest', $release['version']);
            Setting::set('release_url', $release['url']);
        }
    }

    /**
     * The latest release from the API of GitHub.
     *
     * @return mixed The answer, decoded (null when GitHub cannot be reached)
     */
    private static function fetch(): mixed
    {
        $test = getenv(self::TEST_URL_VARIABLE);
        $curl = curl_init(is_string($test) && $test !== '' ? $test : self::URL);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: Carbure'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($curl);
        if (!is_string($response) || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) {
            Logger::info("Latest release of Carbure unknown: " . (is_string($response) ? 'HTTP ' . curl_getinfo($curl, CURLINFO_HTTP_CODE) : curl_error($curl)));
            return null;
        }
        return json_decode($response, true);
    }
}
