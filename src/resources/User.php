<?php

/**
 * User.php
 *
 * User management
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
use Carbure\Lib\Messages;
use Error;

final class User {

    /**
     * Supported interface languages: those of the portal and of the server messages
     */
    private const LANGUAGES = Messages::LANGUAGES;

    /**
     * Display themes of the portal: system (follows the system preference), light, dark
     */
    private const THEMES = ['system', 'light', 'dark'];

    /**
     * Whether the column users.theme exists (migration 2026-10-19_user_theme), checked
     * once per request; null until checked
     */
    private static ?bool $themeColumn = null;

    /**
     * Public columns of a user (never the password). Until the migration of the theme
     * is applied, the theme reads "system": the profile still loads, and with it the
     * banner that offers the administrator to apply the migration.
     *
     * @return string The columns, for a SELECT on the table users
     */
    private static function columns(): string
    {
        $theme = self::hasThemeColumn() ? "theme" : "'system' AS theme";
        return "id, username, firstname, lastname, email, is_admin, language, $theme, alert_threshold";
    }

    /**
     * Whether the column of the display theme exists (its migration applied).
     *
     * @return bool True once the migration 2026-10-19_user_theme is applied
     */
    private static function hasThemeColumn(): bool
    {
        if (self::$themeColumn === null) {
            $row = Db::queryOne("SELECT COUNT(*) AS found FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'theme'", "");
            self::$themeColumn = (int)($row['found'] ?? 0) > 0;
        }
        return self::$themeColumn;
    }

    /**
     * Login. When the second factor is required (Mfa) and not bypassed (known device,
     * trusted browser), a client that announces it supports it (mfa=true) gets a
     * challenge instead of the session, to send with the code to POST /user/login/mfa;
     * the other clients get 403. Unchanged for a user who has no second factor.
     *
     * @param string $username The username
     * @param string $password The password
     * @param array<string, mixed>|null $device Associative array with 'name', 'token' and, optional,
     *                                          'platform' (ios or android, by default the one of the token), 'model',
     *                                          'os_version', 'app_version' (mobile apps only, see Device::register())
     * @param bool|int|string|null $mfa The client supports the second step of the login: true, "true", 1...
     * @return array<string, mixed> The JWT token data (exp, sub, iat, token), or the challenge
     *               of the second factor (mfa, mfa_token, exp; secret and otpauth to register it)
     * @throws Error If the credentials are invalid
     */
    #[ApiRoute('/user/login', method: 'POST', public: true)]
    public static function login(string $username, string $password, ?array $device = null, bool|int|string|null $mfa = null): array {

        // check if the $username and the $password are valid in database
        $sql = "SELECT * FROM users where username=?";
        $user = Db::queryOne($sql, "s", $username);

        if ($user) {
            self::checkNotLocked($user);
        }

        if (!$user || !self::verifyPassword($user, $password)) {
            self::rejectLogin($user);
        }

        // Second factor: the failures are reset only once it is given
        $challenge = Mfa::loginStep($user, $device, filter_var($mfa, FILTER_VALIDATE_BOOLEAN));
        if ($challenge !== null) {
            return $challenge;
        }

        return self::completeLogin($user, $device);
    }

    /**
     * Refuse the login of an account locked after too many failures (the password is not
     * even checked); compared by the database, whose clock wrote the date.
     *
     * @param array<string, mixed> $user The user row
     * @return void
     * @throws Error If the account is locked (423)
     */
    public static function checkNotLocked(array $user): void
    {
        if ($user['locked_until'] !== null
            && Db::queryOne("SELECT ? > NOW() AS locked", "s", $user['locked_until'])['locked']) {
            throw new Error("Account locked after too many failed logins, until " . $user['locked_until']
                . ": ask an administrator to unlock it", 423);
        }
    }

    /**
     * Open the session of a user whose credentials were checked: failures reset, device
     * of the mobile app registered (or the web browser when none is sent), date of the
     * last login.
     *
     * @param array<string, mixed>      $user   The user row
     * @param array<string, mixed>|null $device Associative array with 'name', 'token' and, optional,
     *                                        'platform' (ios or android, by default the one of the token), 'model',
     *                                        'os_version', 'app_version' (mobile apps only, see Device::register())
     * @return array{exp: int, sub: int, iat: int, token: string} The JWT token data
     */
    public static function completeLogin(array $user, ?array $device): array
    {
        if (((int)$user['failed_logins'] > 0 || $user['locked_until'] !== null)) {
            Db::execute("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?", "i", $user['id']);
        }

        // Update or create the device of a mobile app; the web portal sends none: its
        // browser is recorded instead
        if ($device === null) {
            Browser::record((int)$user['id']);
        } else {
            Device::register((int)$user['id'], $device);
        }

        $token = Jwt::createJwt($user['id']);

        // Shown to the administrators (Users tab)
        Db::execute("UPDATE users SET last_login = NOW() WHERE id = ?", "i", $user['id']);

        return $token;
    }

    /**
     * Hash a password.
     *
     * @param string $password The clear password
     * @return string The password hash
     */
    private static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Check a password against the stored hash.
     * Legacy salted SHA256 hashes are upgraded to password_hash() on success.
     *
     * @param array<string, mixed> $user     The user row from database
     * @param string               $password The clear password
     * @return bool True if the password is valid
     */
    private static function verifyPassword(array $user, string $password): bool
    {
        $hash = $user['password'];

        // Legacy hash: salted SHA256 (64 hex chars)
        if (preg_match('/^[a-f0-9]{64}$/', $hash)) {
            $legacy = hash('sha256', Config::get('password_salt') . $password);
            if (!hash_equals($hash, $legacy)) {
                return false;
            }
            Db::execute("UPDATE users SET password=? WHERE id=?", "si", self::hashPassword($password), $user['id']);
            Logger::info("Password hash upgraded for user " . $user['id']);
            return true;
        }

        if (!password_verify($password, $hash)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Db::execute("UPDATE users SET password=? WHERE id=?", "si", self::hashPassword($password), $user['id']);
        }

        return true;
    }

    /**
     * Failed logins in a row before the account is locked
     */
    public const MAX_FAILED_LOGINS = 5;

    /**
     * Duration of the lock
     */
    public const LOCK_HOURS = 24;

    /**
     * Refuse a login with wrong credentials: the failure is counted, and the one that
     * locks the account says so at once (423), so that the login screen tells the user.
     *
     * @param array<string, mixed>|null $user The user (null if unknown)
     * @return never
     * @throws Error Always: 401, or 423 if this failure locked the account
     */
    private static function rejectLogin(?array $user): never
    {
        if ($user && self::recordFailedLogin($user)) {
            throw new Error("Account locked after too many failed logins: ask an administrator to unlock it", 423);
        }
        throw new Error("Invalid username or password", 401);
    }

    /**
     * Count a failed login and lock the account at the limit.
     *
     * @param array<string, mixed> $user The user
     * @return bool True if this failure locked the account
     */
    public static function recordFailedLogin(array $user): bool
    {
        // Counted by the database: concurrent failures (codes of 6 digits tried in parallel)
        // each count, instead of all writing the same value read before
        Db::execute("UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?", "i", $user['id']);
        $failures = (int)(Db::queryOne("SELECT failed_logins FROM users WHERE id = ?", "i", $user['id'])['failed_logins'] ?? 0);
        if ($failures >= self::MAX_FAILED_LOGINS) {
            Db::execute("UPDATE users SET failed_logins = 0, locked_until = NOW() + INTERVAL " . self::LOCK_HOURS . " HOUR WHERE id = ?", "i", $user['id']);
            Logger::warn("Account of user {$user['id']} locked after " . self::MAX_FAILED_LOGINS . " failed logins");
            return true;
        }
        return false;
    }

    /**
     * Unlock an account locked after too many failed logins (administrators).
     *
     * @param int $id The user
     * @return bool True if unlocked
     * @throws Error If not an administrator or the user does not exist
     */
    #[ApiRoute('/user/unlock', method: 'POST', admin: true)]
    public static function unlock(int $id): bool
    {
        if (!Db::queryOne("SELECT id FROM users WHERE id = ?", "i", $id)) {
            throw new Error("User not found", 404);
        }
        Db::execute("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?", "i", $id);
        return true;
    }

    /**
     * Check if the authenticated user is an administrator.
     *
     * @return bool True if the user is an administrator
     */
    public static function isAdmin(): bool
    {
        $user = Db::queryOne("SELECT is_admin FROM users WHERE id=?", "i", Jwt::getUserIdFromToken());
        return $user !== null && (bool)$user['is_admin'];
    }

    /**
     * Require the authenticated user to be an administrator.
     *
     * @return void
     * @throws Error If the user is not an administrator
     */
    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            throw new Error("Forbidden - Administrator only", 403);
        }
    }

    /**
     * Get all users (administrator) or the authenticated user only, with the date
     * of their last login.
     * @return list<array<string, mixed>> List of users (without passwords)
     */
    #[ApiRoute('/user', method: 'GET')]
    public static function get(): array
    {
        $sql = "SELECT " . self::columns() . ", last_login, locked_until FROM users";
        if (self::isAdmin()) {
            $stmt = Db::execute($sql . " ORDER BY username", "");
        } else {
            $stmt = Db::execute($sql . " WHERE id=?", "i", Jwt::getUserIdFromToken());
        }
        $result = $stmt->get_result();

        $users = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }

        return $users;
    }

    /**
     * Get the authenticated user profile.
     * @return array<string, mixed> The user (without password)
     * @throws Error If the user does not exist anymore
     */
    #[ApiRoute('/user/me', method: 'GET')]
    public static function me(): array
    {
        $sql = "SELECT " . self::columns() . " FROM users WHERE id=?";
        $user = Db::queryOne($sql, "i", Jwt::getUserIdFromToken());

        if (!$user) {
            throw new Error("User not found", 404);
        }

        return $user;
    }

    /**
     * Create a new user.
     *
     * @param string      $username  The username
     * @param string      $password  The password (will be hashed)
     * @param string      $email     The email
     * @param string|null $firstname The first name (optional)
     * @param string|null $lastname  The last name (optional)
     * @param bool|int|string|null $is_admin Administrator profile (optional, user by default): true, "true", 1...
     * @return array<string, mixed> The created user data with id
     * @throws Error If the username or email already exists or the caller is not an administrator
     */
    #[ApiRoute('/user', method: 'POST', admin: true)]
    public static function create(string $username, string $password, string $email, ?string $firstname = null, ?string $lastname = null, bool|int|string|null $is_admin = false): array
    {
        // Check if username already exists
        $sql = "SELECT id FROM users WHERE username=?";
        $stmt = Db::execute($sql, "s", $username);
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            throw new Error("Username already exists", 409);
        }

        // Check if email already exists
        $sql = "SELECT id FROM users WHERE email=?";
        $stmt = Db::execute($sql, "s", $email);
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            throw new Error("Email already exists", 409);
        }

        // Hash the password
        $hashedPassword = self::hashPassword($password);

        // Insert the new user
        $isAdmin = filter_var($is_admin, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $sql = "INSERT INTO users (username, password, email, firstname, lastname, is_admin) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = Db::execute($sql, "sssssi", $username, $hashedPassword, $email, $firstname, $lastname, $isAdmin);

        $userId = $stmt->insert_id;

        return array(
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'is_admin' => $isAdmin
        );
    }

    /**
     * Delete a user by id.
     *
     * @param int $id The user id to delete
     * @return bool True if deleted successfully
     * @throws Error If the user is not found or the caller is not an administrator
     */
    #[ApiRoute('/user', method: 'DELETE', admin: true)]
    public static function delete(int $id): bool
    {
        if ($id === (int)Jwt::getUserIdFromToken()) {
            throw new Error("You cannot delete your own account", 400);
        }

        // Check if user exists
        $sql = "SELECT id FROM users WHERE id=?";
        $stmt = Db::execute($sql, "i", $id);
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            throw new Error("User not found", 404);
        }

        // The household transactions are attached to their owner
        $owned = Db::queryOne("SELECT COUNT(*) AS count FROM bank_transaction WHERE user=?", "i", $id);
        if ($owned['count'] > 0) {
            throw new Error("User owns bank transactions and cannot be deleted", 409);
        }

        // Delete the user
        $sql = "DELETE FROM users WHERE id=?";
        Db::execute($sql, "i", $id);

        return true;
    }

    /**
     * Update a user.
     *
     * @param int         $id        The user id to update
     * @param string|null $email     The email (optional)
     * @param string|null $firstname The first name (optional)
     * @param string|null $lastname  The last name (optional)
     * @param string|null $password  The new password (optional, will be hashed)
     * @param string|null $language  The interface language (optional, fr|en|es|it|de|pt)
     * @param float|string|null $alertThreshold Push alert threshold for new expenses
     *                                    (optional, "" or 0 disables the alerts)
     * @param bool|int|string|null $is_admin Administrator profile (optional, administrators only): true, "true", 1...
     * @param string|null $theme     The display theme of the portal (optional, system|light|dark)
     * @return array<string, mixed> The updated user data
     * @throws Error If the user is not found, email already exists or the caller is not allowed
     */
    #[ApiRoute('/user', method: 'PUT')]
    public static function update(int $id, ?string $email = null, ?string $firstname = null, ?string $lastname = null, ?string $password = null,
        ?string $language = null, float|int|string|null $alertThreshold = null, bool|int|string|null $is_admin = null,
        ?string $theme = null): array
    {
        // A user can only update his own profile, unless administrator
        if ($id !== (int)Jwt::getUserIdFromToken()) {
            self::requireAdmin();
        }

        $is_admin = self::adminFlag($id, $is_admin);
        $currentUser = self::findForUpdate($id);
        self::checkEmailFree($id, $email, $currentUser['email']);

        // Update only the provided fields
        $fields = [...self::updateFields($email, $firstname, $lastname, $language, $is_admin, $alertThreshold, $password),
            ...self::themeField($theme)];
        if (!empty($fields)) {
            $types = implode('', array_column($fields, 1)) . "i";
            $params = [...array_column($fields, 2), $id];

            $sql = "UPDATE users SET " . implode(", ", array_map(fn($field) => "$field[0]=?", $fields)) . " WHERE id=?";
            Db::execute($sql, $types, ...$params);
        }

        // A new password revokes the browsers trusted for the second factor
        if (in_array('password', array_column($fields, 0), true)) {
            Mfa::passwordChanged($id);
        }

        // Get updated user data
        $updatedUser = Db::queryOne("SELECT " . self::columns() . " FROM users WHERE id=?", "i", $id);
        if ($updatedUser === null) {
            throw new Error("User not found", 404);
        }

        return $updatedUser;
    }

    /**
     * Administrator flag of an update: only an administrator changes it, and the last
     * administrator keeps his role.
     *
     * @param int                  $id       The user id to update
     * @param bool|int|string|null $is_admin The flag sent (true, "true", 1...), null if not changed
     * @return int|null 1 or 0, null if not changed
     * @throws Error If the caller is not an administrator or no administrator would remain
     */
    private static function adminFlag(int $id, bool|int|string|null $is_admin): ?int
    {
        if ($is_admin === null) {
            return null;
        }
        self::requireAdmin();
        $flag = filter_var($is_admin, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if ($flag === 0 && !Db::queryOne("SELECT id FROM users WHERE is_admin = 1 AND id <> ? LIMIT 1", "i", $id)) {
            throw new Error("At least one administrator is required", 409);
        }
        return $flag;
    }

    /**
     * Current row of the user to update.
     *
     * @param int $id The user id
     * @return array<string, mixed> The user row
     * @throws Error If the user is not found
     */
    private static function findForUpdate(int $id): array
    {
        $result = Db::execute("SELECT * FROM users WHERE id=?", "i", $id)->get_result();
        $user = $result->num_rows == 0 ? null : $result->fetch_assoc();
        if (!$user) {
            throw new Error("User not found", 404);
        }
        return $user;
    }

    /**
     * Check that a new email is not used by another user.
     *
     * @param int         $id      The user id to update
     * @param string|null $email   The email sent (optional)
     * @param mixed       $current The current email of the user
     * @return void
     * @throws Error If the email already exists
     */
    private static function checkEmailFree(int $id, ?string $email, mixed $current): void
    {
        if (!$email || $email === $current) {
            return;
        }
        $result = Db::execute("SELECT id FROM users WHERE email=? AND id!=?", "si", $email, $id)->get_result();
        if ($result->num_rows > 0) {
            throw new Error("Email already exists", 409);
        }
    }

    /**
     * Columns to update with their bind type and value, for the provided fields only.
     *
     * @param string|null            $email          The email
     * @param string|null            $firstname      The first name
     * @param string|null            $lastname       The last name
     * @param string|null            $language       The interface language (fr|en|es|it|de|pt)
     * @param int|null               $is_admin       Administrator flag (1 or 0)
     * @param float|int|string|null  $alertThreshold Push alert threshold ("" or 0 disables)
     * @param string|null            $password       The new password (hashed here)
     * @return list<array{0: string, 1: string, 2: mixed}> [column, bind type, value]
     * @throws Error If the language or the alert threshold is invalid
     */
    private static function updateFields(?string $email, ?string $firstname, ?string $lastname, ?string $language,
        ?int $is_admin, float|int|string|null $alertThreshold, ?string $password): array
    {
        $fields = [];
        foreach (['email' => $email, 'firstname' => $firstname, 'lastname' => $lastname] as $column => $value) {
            if ($value !== null) {
                $fields[] = [$column, "s", $value];
            }
        }
        if ($language !== null) {
            if (!in_array($language, self::LANGUAGES, true)) {
                throw new Error("Unsupported language: $language", 400);
            }
            $fields[] = ["language", "s", $language];
        }
        if ($is_admin !== null) {
            $fields[] = ["is_admin", "i", $is_admin];
        }
        if ($alertThreshold !== null) {
            $fields[] = ["alert_threshold", "d", self::alertThresholdValue($alertThreshold)];
        }
        if ($password !== null && $password !== '') {
            $fields[] = ["password", "s", self::hashPassword($password)];
        }
        return $fields;
    }

    /**
     * Column of the display theme to update, if sent.
     *
     * @param string|null $theme The display theme (system|light|dark)
     * @return list<array{0: string, 1: string, 2: mixed}> [column, bind type, value], empty if not sent
     * @throws Error If the theme is unknown, or its migration is not applied yet (409)
     */
    private static function themeField(?string $theme): array
    {
        if ($theme === null) {
            return [];
        }
        if (!in_array($theme, self::THEMES, true)) {
            throw new Error("Unsupported theme: $theme", 400);
        }
        if (!self::hasThemeColumn()) {
            throw new Error("Apply the pending database migrations first: the theme cannot be saved yet", 409);
        }
        return [["theme", "s", $theme]];
    }

    /**
     * Stored value of an alert threshold: "" or 0 disables the alerts (null).
     *
     * @param float|int|string $alertThreshold The threshold sent
     * @return float|null The threshold rounded to cents, null when disabled
     * @throws Error If the threshold is not a positive amount
     */
    public static function alertThresholdValue(float|int|string $alertThreshold): ?float
    {
        if ($alertThreshold !== '' && (!is_numeric($alertThreshold) || $alertThreshold < 0)) {
            throw new Error("The alert threshold must be a positive amount", 400);
        }
        return ($alertThreshold === '' || (float)$alertThreshold == 0) ? null : round((float)$alertThreshold, 2);
    }
}
