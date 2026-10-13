<?php

/**
 * User.php
 *
 * User management
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class User {

    /**
     * Supported interface languages
     */
    private const LANGUAGES = ['fr', 'en'];

    /**
     * Public columns of a user (never the password)
     */
    private const COLUMNS = "id, username, firstname, lastname, email, is_admin, language, alert_threshold";

    /**
     * Login.
     *
     * @param string $username The username
     * @param string $password The password
     * @param array<string, mixed>|null $device Associative array with 'name' and 'token' (mobile app only)
     * @return array{exp: int, sub: int, iat: int, token: string} The JWT token data
     * @throws Error If the credentials are invalid
     */
    #[ApiRoute('/user/login', method: 'POST', public: true)]
    public static function login(string $username, string $password, ?array $device = null): array {

        // check if the $username and the $password are valid in database
        $sql = "SELECT * FROM users where username=?";
        $user = Db::queryOne($sql, "s", $username);

        // Locked after too many failures (the password is not even checked);
        // compared by the database, whose clock wrote the date
        if ($user && $user['locked_until'] !== null
            && Db::queryOne("SELECT ? > NOW() AS locked", "s", $user['locked_until'])['locked']) {
            throw new Error("Account locked after too many failed logins, until " . $user['locked_until']
                . ": ask an administrator to unlock it", 423);
        }

        if (!$user || !self::verifyPassword($user, $password)) {
            self::rejectLogin($user);
        }

        if (((int)$user['failed_logins'] > 0 || $user['locked_until'] !== null)) {
            Db::execute("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?", "i", $user['id']);
        }

        // Update or create the device (the web portal does not send one)
        if (!empty($device['token'])) {
            Device::createOrUpdate($user['id'], $device['name'] ?? '', $device['token']);
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
    private static function recordFailedLogin(array $user): bool
    {
        $failures = (int)$user['failed_logins'] + 1;
        if ($failures >= self::MAX_FAILED_LOGINS) {
            Db::execute("UPDATE users SET failed_logins = 0, locked_until = NOW() + INTERVAL " . self::LOCK_HOURS . " HOUR WHERE id = ?", "i", $user['id']);
            Logger::warn("Account {$user['username']} locked after " . self::MAX_FAILED_LOGINS . " failed logins");
            return true;
        }
        Db::execute("UPDATE users SET failed_logins = ? WHERE id = ?", "ii", $failures, $user['id']);
        return false;
    }

    /**
     * Unlock an account locked after too many failed logins (administrators).
     *
     * @param int $id The user
     * @return bool True if unlocked
     * @throws Error If not an administrator or the user does not exist
     */
    #[ApiRoute('/user/unlock', method: 'POST')]
    public static function unlock(int $id): bool
    {
        self::requireAdmin();
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
        $sql = "SELECT " . self::COLUMNS . ", last_login, locked_until FROM users";
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
        $sql = "SELECT " . self::COLUMNS . " FROM users WHERE id=?";
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
    #[ApiRoute('/user', method: 'POST')]
    public static function create(string $username, string $password, string $email, ?string $firstname = null, ?string $lastname = null, bool|int|string|null $is_admin = false): array
    {
        self::requireAdmin();

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
    #[ApiRoute('/user', method: 'DELETE')]
    public static function delete(int $id): bool
    {
        self::requireAdmin();

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
     * @param string|null $language  The interface language (optional, fr|en)
     * @param float|string|null $alertThreshold Push alert threshold for new expenses
     *                                    (optional, "" or 0 disables the alerts)
     * @param bool|int|string|null $is_admin Administrator profile (optional, administrators only): true, "true", 1...
     * @return array<string, mixed> The updated user data
     * @throws Error If the user is not found, email already exists or the caller is not allowed
     */
    #[ApiRoute('/user', method: 'PUT')]
    public static function update(int $id, ?string $email = null, ?string $firstname = null, ?string $lastname = null, ?string $password = null,
        ?string $language = null, float|int|string|null $alertThreshold = null, bool|int|string|null $is_admin = null): array
    {
        // A user can only update his own profile, unless administrator
        if ($id !== (int)Jwt::getUserIdFromToken()) {
            self::requireAdmin();
        }

        $is_admin = self::adminFlag($id, $is_admin);
        $currentUser = self::findForUpdate($id);
        self::checkEmailFree($id, $email, $currentUser['email']);

        // Update only the provided fields
        $fields = self::updateFields($email, $firstname, $lastname, $language, $is_admin, $alertThreshold, $password);
        if (!empty($fields)) {
            $types = implode('', array_column($fields, 1)) . "i";
            $params = [...array_column($fields, 2), $id];

            $sql = "UPDATE users SET " . implode(", ", array_map(fn($field) => "$field[0]=?", $fields)) . " WHERE id=?";
            Db::execute($sql, $types, ...$params);
        }

        // Get updated user data
        $updatedUser = Db::queryOne("SELECT " . self::COLUMNS . " FROM users WHERE id=?", "i", $id);
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
     * @param string|null            $language       The interface language (fr|en)
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
     * Stored value of an alert threshold: "" or 0 disables the alerts (null).
     *
     * @param float|int|string $alertThreshold The threshold sent
     * @return float|null The threshold rounded to cents, null when disabled
     * @throws Error If the threshold is not a positive amount
     */
    private static function alertThresholdValue(float|int|string $alertThreshold): ?float
    {
        if ($alertThreshold !== '' && (!is_numeric($alertThreshold) || $alertThreshold < 0)) {
            throw new Error("The alert threshold must be a positive amount", 400);
        }
        return ($alertThreshold === '' || (float)$alertThreshold == 0) ? null : round((float)$alertThreshold, 2);
    }
}
