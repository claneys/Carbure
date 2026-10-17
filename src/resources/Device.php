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
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\PushRelay;
use Error;

final class Device {

    /** Platforms of the devices: ios (APNs, the only app before Android) or android (FCM) */
    const PLATFORMS = ['ios', 'android'];

    /** Whether the column devices.platform exists (null: not checked yet) */
    private static ?bool $platformColumn = null;

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
     *               token, platform (ios before the migration that adds it), lastLogin
     */
    public static function get(int $userId): array
    {
        $platform = self::hasPlatform() ? 'platform' : "'ios' AS platform";
        $sql = "SELECT id, user_id, name, token, $platform, lastLogin 
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
     * Create a new device or update if it already exists.
     *
     * @param int    $userId   The user ID
     * @param string $name     The device name
     * @param string $token    The unique device token
     * @param string $platform ios (default: the iOS app sends no platform) or android
     * @return array<string, mixed> The created or updated device data with id
     * @throws Error If the platform is not known (400)
     */
    public static function createOrUpdate(int $userId, string $name, string $token, string $platform = 'ios'): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new Error("Unknown device platform: $platform (" . implode(', ', self::PLATFORMS) . ")", 400);
        }
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

        return array(
            'id' => $deviceId,
            'user_id' => $userId,
            'name' => $name,
            'token' => $token,
            'platform' => $platform
        );
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
