<?php

/**
 * Device.php
 *
 * Device management
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\PushRelay;
use Error;

final class Device {

    /** Platforms of the devices: ios (APNs, the only app before Android) or android (FCM) */
    const PLATFORMS = ['ios', 'android'];

    /** Optional details of a device sent by the mobile apps, and their maximum length */
    const DETAILS = ['model' => 100, 'os_version' => 50, 'app_version' => 20];

    /** Whether the column devices.platform exists (null: not checked yet) */
    private static ?bool $platformColumn = null;

    /** Whether the columns devices.model, os_version, app_version exist (null: not checked yet) */
    private static ?bool $detailsColumns = null;

    /**
     * Get all devices of the authenticated user.
     *
     * @return list<array<string, mixed>> List of all devices for the user
     */
    #[ApiRoute('/device', method: 'GET')]
    public static function getMine(): array
    {
        return self::get(Jwt::getUserIdFromToken());
    }

    /**
     * Address of the API given to the mobile apps by the QR code of the devices
     * (https://carbure.org/app/?server=...): the setting public_url followed by /api/, the
     * portal may be opened through another address (local network); null without it (the
     * portal uses its own address).
     *
     * @return array{server: string|null}
     */
    #[ApiRoute('/device/link', method: 'GET')]
    public static function link(): array
    {
        return ['server' => Config::has('public_url') ? OAuth::baseUrl() . '/api/' : null];
    }

    /**
     * Delete a device of the authenticated user (e.g. an old phone).
     *
     * @param int $id The device id
     * @return bool True if deleted
     * @throws Error If the device does not exist or belongs to another user
     */
    #[ApiRoute('/device', method: 'DELETE')]
    public static function delete(int $id): bool
    {
        $stmt = Db::execute("DELETE FROM devices WHERE id=? AND user_id=?", "ii", $id, Jwt::getUserIdFromToken());

        if ($stmt->affected_rows === 0) {
            throw new Error("Device not found", 404);
        }

        return true;
    }

    /**
     * Get all devices for a user.
     *
     * @param int $userId The user ID
     * @return list<array<string, mixed>> List of all devices for the user: id, user_id, name,
     *               token, platform (ios before the migration that adds it), lastLogin, model,
     *               os_version, app_version (null when unknown or before the migration that adds them)
     */
    public static function get(int $userId): array
    {
        $platform = self::hasPlatform() ? 'platform' : "'ios' AS platform";
        $details = self::hasDetails() ? 'model, os_version, app_version'
            : 'NULL AS model, NULL AS os_version, NULL AS app_version';
        $sql = "SELECT id, user_id, name, token, $platform, lastLogin, $details 
                FROM devices 
                WHERE user_id=? 
                ORDER BY lastLogin DESC";
        $stmt = Db::execute($sql, "i", $userId);
        $result = $stmt->get_result();

        $devices = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $devices[] = $row;
            }
        }

        return $devices;
    }

    /**
     * Get a device by token.
     *
     * @param string $token The device token
     * @return array<string, mixed>|null The device data or null if not found
     */
    private static function getByToken(string $token): ?array
    {
        $sql = "SELECT id, user_id, name, token, lastLogin 
                FROM devices 
                WHERE token=?";
        $stmt = Db::execute($sql, "s", $token);
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            return $result->fetch_assoc();
        }

        return null;
    }

    /**
     * Register the device sent at login by a mobile app ('name', 'token' and, optional,
     * 'platform', 'model', 'os_version', 'app_version'), nothing without a token (web portal).
     *
     * @param int                       $userId The user ID
     * @param array<string, mixed>|null $device The device sent
     * @return array<string, mixed>|null The device registered, null without token
     * @throws Error If the platform is not known (400)
     */
    public static function register(int $userId, ?array $device): ?array
    {
        $token = is_scalar($device['token'] ?? null) ? trim((string)$device['token']) : '';
        if ($token === '') {
            return null;
        }
        $name = is_scalar($device['name'] ?? null) ? (string)$device['name'] : '';
        return self::createOrUpdate($userId, $name, $token, self::platformOf($token, $device['platform'] ?? null),
            self::details($device));
    }

    /**
     * Platform of a device: the one sent (case and spaces ignored) or, when none is sent
     * (the first apps did not), the one of its push token: 64 hexadecimal characters for
     * APNs (ios), anything else (FCM tokens: long, with ':', '-', '_') for android.
     *
     * @param string $token    The push token of the device
     * @param mixed  $platform The platform sent (null or empty: none)
     * @return string ios or android
     * @throws Error If the platform sent is not known (400)
     */
    public static function platformOf(string $token, mixed $platform = null): string
    {
        $platform = is_scalar($platform) ? strtolower(trim((string)$platform)) : '';
        if ($platform === '') {
            return preg_match('/^[0-9a-f]{64}$/i', trim($token)) === 1 ? 'ios' : 'android';
        }
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new Error("Unknown device platform: $platform (" . implode(', ', self::PLATFORMS) . ")", 400);
        }
        return $platform;
    }

    /**
     * Optional details of a device sent by a mobile app: model (e.g. iPhone16,1, Google
     * Pixel 8), os_version (iOS 26.0, Android 15), app_version (1.2); trimmed, cut to their
     * maximum length, null when missing, empty or not a text.
     *
     * @param array<string, mixed> $device The device sent
     * @return array{model: ?string, os_version: ?string, app_version: ?string}
     */
    public static function details(array $device): array
    {
        $details = ['model' => null, 'os_version' => null, 'app_version' => null];
        foreach (self::DETAILS as $field => $length) {
            $value = is_scalar($device[$field] ?? null) ? trim((string)$device[$field]) : '';
            $details[$field] = $value === '' ? null : mb_substr($value, 0, $length);
        }
        return $details;
    }

    /**
     * Create a new device or update if it already exists.
     *
     * @param int    $userId   The user ID
     * @param string $name     The device name
     * @param string $token    The unique device token
     * @param string $platform ios or android; empty: the one of the token (see platformOf())
     * @param array<string, mixed> $details Optional model, os_version, app_version (see details()),
     *                                      ignored before the migration that adds them
     * @return array<string, mixed> The created or updated device data with id
     * @throws Error If the platform is not known (400)
     */
    public static function createOrUpdate(int $userId, string $name, string $token, string $platform = '', array $details = []): array
    {
        $platform = self::platformOf($token, $platform);
        $details = self::details($details);
        // Check if device with this token already exists
        $existingDevice = self::getByToken($token);

        if ($existingDevice) {
            // Update existing device (it may now belong to another user of the household)
            $sql = "UPDATE devices 
                    SET name=?, user_id=?, lastLogin=NOW() 
                    WHERE token=?";
            Db::execute($sql, "sis", $name, $userId, $token);
            
            $deviceId = $existingDevice['id'];
        } else {
            // Create new device
            $sql = "INSERT INTO devices (user_id, name, token, lastLogin) 
                    VALUES (?, ?, ?, NOW())";
            $stmt = Db::execute($sql, "iss", $userId, $name, $token);
            
            $deviceId = $stmt->insert_id;
        }
        if (self::hasPlatform()) {
            Db::execute("UPDATE devices SET platform=? WHERE token=?", "ss", $platform, $token);
        }
        if (self::hasDetails()) {
            Db::execute("UPDATE devices SET model=?, os_version=?, app_version=? WHERE token=?", "ssss",
                $details['model'], $details['os_version'], $details['app_version'], $token);
        }

        return array(
            'id' => $deviceId,
            'user_id' => $userId,
            'name' => $name,
            'token' => $token,
            'platform' => $platform
        ) + $details;
    }

    /**
     * Whether the column devices.platform exists: the code runs before the migration
     * that adds it (2026-10-23_device_platform), every device being then an iOS one.
     *
     * @return bool
     */
    private static function hasPlatform(): bool
    {
        return self::$platformColumn ??= (int)Db::queryOne("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'platform'", "")['n'] > 0;
    }

    /**
     * Whether the columns devices.model, os_version and app_version exist: the code runs
     * before the migration that adds them (2026-10-25_device_model), the details being then
     * ignored.
     *
     * @return bool
     */
    private static function hasDetails(): bool
    {
        return self::$detailsColumns ??= (int)Db::queryOne("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'app_version'", "")['n'] > 0;
    }

    /**
     * Send push notification to all devices of a user, through the push service
     * (Carbure Push); the devices it reports unregistered (app removed, token expired)
     * are forgotten.
     *
     * @param int $userId The user ID
     * @param string $title Notification title
     * @param string $body Notification body
     * @param int $badge Badge number to display on the app icon (optional)
     * @param array<string, mixed> $data Optional custom data payload
     * @return array<string, array<string, mixed>> Results for each device (see PushRelay::sendToMultiple())
     */
    public static function sendNotification(int $userId, string $title, string $body, int $badge = 0, array $data = []): array
    {
        // Get all devices for the user
        $devices = self::get($userId);
        
        if (empty($devices)) {
            Logger::info("No devices found for user $userId");
            return [];
        }
        
        // Tokens and their platform
        $deviceTokens = array_column($devices, 'platform', 'token');
        
        // Send notifications
        Logger::info("Sending push notification to " . count($deviceTokens) . " device(s) for user $userId");
        $results = PushRelay::sendToMultiple($deviceTokens, $title, $body, $badge, $data);
        foreach ($results as $token => $result) {
            if (($result['status'] ?? null) === 'unregistered') {
                Db::execute("DELETE FROM devices WHERE token=? AND user_id=?", "si", (string)$token, $userId);
                Logger::info("Device of user $userId removed: unregistered from the push notifications");
            }
        }
        return $results;
    }

    /**
     * Test sending a notification to the user's devices.
     *
     * @return array<string, array<string, mixed>> Results of the test notification
     */
    #[ApiRoute('/device/push', method: 'POST')]
    public static function testNotification(): array
    {
        $userId = Jwt::getUserIdFromToken();
        return self::sendNotification($userId, "Test Notification", "This is a test notification from Carbure.");
    }
}
