<?php

/**
 * Bank.php
 *
 * Bank class to sync transactions from a bank
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

require_once "Transaction.php";

final class Bank {
    
    /**
     * Get the bank accounts of the household (every user sees all of them).
     * Each bank account is returned with bankId = account_number@bank_name
     * @return list<array<string, mixed>> List of bank accounts with bankId and details
     *               (label: name given to the account, null if none)
     */
    #[ApiRoute('/bank', method: 'GET')]
    public static function get(): array
    {
        $sql = "SELECT MIN(id) AS id, account_number, bank_name, MAX(label) AS label FROM bank_account
                GROUP BY account_number, bank_name ORDER BY bank_name, account_number";
        $stmt = Db::execute($sql, "");
        $result = $stmt->get_result();
        
        $accounts = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $accounts[] = array(
                    'id' => $row['id'],
                    'bankId' => $row['account_number'] . '@' . $row['bank_name'],
                    'account_number' => $row['account_number'],
                    'bank_name' => $row['bank_name'],
                    'label' => $row['label']
                );
            }
        }
        
        return $accounts;
    }
    
    /**
     * Validate a bank account identifier and woob backend name.
     *
     * @param string $accountNumber The account identifier in woob
     * @param string $bankName      The woob backend name
     * @return void
     * @throws Error If one of them is invalid
     */
    private static function validateAccount(string $accountNumber, string $bankName): void
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $accountNumber)) {
            throw new Error("Invalid account number", 400);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $bankName)) {
            throw new Error("Invalid bank (woob backend) name", 400);
        }
    }

    /**
     * Normalize the name given to an account: trimmed, without control characters,
     * null when empty.
     *
     * @param string|null $label The name given to the account
     * @return string|null The name to store
     * @throws Error If it is too long
     */
    private static function normalizeLabel(?string $label): ?string
    {
        $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$label) ?? '');
        if ($label === '') {
            return null;
        }
        if (mb_strlen($label) > 100) {
            throw new Error("Account name too long (100 characters max)", 400);
        }
        return $label;
    }

    /**
     * Get a bank account of the household.
     *
     * @param int $id The account id
     * @return array<string, mixed> The account
     * @throws Error If the account does not exist
     */
    private static function existingAccount(int $id): array
    {
        $account = Db::queryOne("SELECT * FROM bank_account WHERE id = ?", "i", $id);
        if (!$account) {
            throw new Error("Account not found", 404);
        }
        return $account;
    }

    /**
     * All the bank accounts of the household with the user who added them and the
     * result of their last synchronization (administrators).
     *
     * @return list<array<string, mixed>> The accounts: id, bankId, account_number, bank_name,
     *               label (name given to the account, null if none), user_id and username
     *               (who added it), last_sync_at, last_sync_status (OK|ERROR), last_sync_message
     */
    #[ApiRoute('/bank/accounts', method: 'GET')]
    public static function accounts(): array
    {
        User::requireAdmin();
        $sql = "SELECT a.id, a.account_number, a.bank_name, a.label, a.user_id, u.username,
                       a.last_sync_at, a.last_sync_status, a.last_sync_message
                FROM bank_account a JOIN users u ON u.id = a.user_id
                ORDER BY a.bank_name, a.account_number, u.username";
        $accounts = [];
        foreach (Db::execute($sql, "")->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $accounts[] = ['id' => $row['id'], 'bankId' => $row['account_number'] . '@' . $row['bank_name']] + $row;
        }
        return $accounts;
    }

    /**
     * Follow a bank account (it is synchronized from now on).
     *
     * @param string      $account_number The account identifier in woob
     * @param string      $bank_name      The woob backend name
     * @param string|null $label          Name given to the account (optional)
     * @return array<string, mixed> The account (user_id: the administrator who added it)
     * @throws Error If invalid or already followed by the household
     */
    #[ApiRoute('/bank', method: 'POST')]
    public static function create(string $account_number, string $bank_name, ?string $label = null): array
    {
        User::requireAdmin();
        self::validateAccount($account_number, $bank_name);
        $label = self::normalizeLabel($label);
        $userId = (int)Jwt::getUserIdFromToken();

        if (Db::queryOne("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ?", "ss", $account_number, $bank_name)) {
            throw new Error("This account is already followed", 409);
        }

        $stmt = Db::execute("INSERT INTO bank_account (bank_name, account_number, label, user_id) VALUES (?, ?, ?, ?)", "sssi", $bank_name, $account_number, $label, $userId);

        return ['id' => $stmt->insert_id, 'bankId' => "$account_number@$bank_name", 'account_number' => $account_number, 'bank_name' => $bank_name, 'label' => $label, 'user_id' => $userId];
    }

    /**
     * Modify a followed bank account.
     *
     * @param int         $id             The account id
     * @param string      $account_number The account identifier in woob
     * @param string      $bank_name      The woob backend name
     * @param string|null $label          Name given to the account: omitted (null) keeps
     *                                    the current one, empty removes it
     * @return array<string, mixed> The account
     * @throws Error If not found, invalid or already followed by the household
     */
    #[ApiRoute('/bank', method: 'PUT')]
    public static function update(int $id, string $account_number, string $bank_name, ?string $label = null): array
    {
        User::requireAdmin();
        $account = self::existingAccount($id);
        self::validateAccount($account_number, $bank_name);
        $label = $label === null ? $account['label'] : self::normalizeLabel($label);
        $userId = (int)$account['user_id'];

        if (Db::queryOne("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ? AND id <> ?", "ssi", $account_number, $bank_name, $id)) {
            throw new Error("This account is already followed", 409);
        }

        Db::execute("UPDATE bank_account SET account_number = ?, bank_name = ?, label = ? WHERE id = ?", "sssi", $account_number, $bank_name, $label, $id);

        return ['id' => $id, 'bankId' => "$account_number@$bank_name", 'account_number' => $account_number, 'bank_name' => $bank_name, 'label' => $label, 'user_id' => $userId];
    }

    /**
     * Stop following a bank account (its transactions are kept).
     *
     * @param int $id The account id
     * @return bool True if deleted
     * @throws Error If not found
     */
    #[ApiRoute('/bank', method: 'DELETE')]
    public static function delete(int $id): bool
    {
        User::requireAdmin();
        self::existingAccount($id);
        Db::execute("DELETE FROM bank_account WHERE id = ?", "i", $id);
        return true;
    }

    /**
     * Bank backends configured in woob (name and module), to choose the bank of an account.
     *
     * @return list<array{name: mixed, module: mixed}> The backends
     */
    #[ApiRoute('/bank/backends', method: 'GET')]
    public static function backends(): array
    {
        User::requireAdmin();
        return Woob::listBackends();
    }

    /**
     * Banks supported by woob (module and description); a bank must be configured
     * in woob (from the portal, POST /bank/backend) before its accounts can be synchronized.
     *
     * @return list<array{module: mixed, description: mixed}> The modules
     */
    #[ApiRoute('/bank/modules', method: 'GET')]
    public static function modules(): array
    {
        User::requireAdmin();
        return Woob::listBankModules();
    }

    /**
     * Settings asked by a woob bank module, to configure the bank from the portal
     * (administrators).
     *
     * @param string $module The woob module (e.g. bnp)
     * @return array<string, mixed> module, description, fields [{key, label, description, default, required, masked, choices}]
     * @throws Error If the module name is invalid
     */
    #[ApiRoute('/bank/module', method: 'GET')]
    public static function module(string $module): array
    {
        User::requireAdmin();
        if (!preg_match('/^[a-z0-9_]{1,50}$/', $module)) {
            throw new Error("Invalid woob module", 400);
        }
        return Woob::moduleFields($module);
    }

    /**
     * Configure a bank in woob from the portal (administrators): the credentials
     * are given to woob, which keeps them; Carbure stores and logs nothing of them.
     * The accounts woob then finds are returned, to follow them.
     *
     * @param string $module   The woob module (e.g. bnp)
     * @param string $backend  Name of the woob backend (e.g. bnp, bnp_pro)
     * @param mixed  $settings The settings of the module (login, password...): key => value
     *                         (anything else is taken as no setting, as sent by the client)
     * @return array{backend: string, accounts: list<array<string, mixed>>} backend, accounts
     *               [{bankId, account_number, bank_name, label, balance, currency, followed}]
     * @throws Error If a setting is missing or invalid, or woob refuses the bank
     */
    #[ApiRoute('/bank/backend', method: 'POST')]
    public static function createBackend(string $module, string $backend, mixed $settings = []): array
    {
        User::requireAdmin();
        if (!preg_match('/^[a-z0-9_]{1,50}$/', $module) || !preg_match('/^[a-z0-9_-]{1,50}$/', $backend)) {
            throw new Error("Invalid woob module or bank name (lowercase letters, digits, - and _)", 400);
        }
        if (in_array($backend, array_column(Woob::listBackends(), 'name'), true)) {
            throw new Error("A bank named $backend is already configured in woob", 409);
        }

        // Every setting of the module is given (woob would ask for a missing one),
        // required ones present, values on one line, checked against the module's rules
        $params = [];
        foreach (Woob::moduleFields($module)['fields'] as $field) {
            $value = self::settingValue($field, $settings);
            self::checkSetting($field, $value);
            $params[$field['key']] = $value;
        }

        try {
            Woob::addBackend($module, $backend, $params);
        } catch (Exception $e) {
            throw new Error($e->getMessage(), 400);
        }

        // Accounts of the new bank, to follow them: the first connection to the bank.
        // If the bank refuses it (wrong credentials...), the backend is removed from
        // woob so that the form can be sent again
        try {
            $accounts = self::discover($backend);
        } catch (Exception $e) {
            Woob::removeBackend($backend);
            throw new Error("The bank refused the connection, check the credentials: " . $e->getMessage(), 400);
        }
        return ['backend' => $backend, 'accounts' => $accounts];
    }

    /**
     * Value of a setting of a woob module, as sent by the portal (trimmed), else its
     * default value.
     *
     * @param array{key: string, label: string, default: string, required: bool} $field The setting
     * @param mixed $settings The settings sent: key => value (anything else: no setting)
     * @return string The value
     * @throws Error If a required setting is missing
     */
    private static function settingValue(array $field, mixed $settings): string
    {
        $value = is_array($settings) ? trim((string)($settings[$field['key']] ?? '')) : '';
        if ($value === '') {
            $value = $field['default'];
        }
        if ($value === '' && $field['required']) {
            throw new Error("Missing setting: " . $field['label'], 400);
        }
        return $value;
    }

    /**
     * Check the value of a setting: on one line, without quotes, among the choices of
     * the module and matching its format.
     *
     * @param array{label: string, regexp: string|null, choices: list<array{value: string, label: string}>} $field The setting
     * @param string $value The value
     * @return void
     * @throws Error If the value is not valid
     */
    private static function checkSetting(array $field, string $value): void
    {
        if (preg_match('/[\x00-\x1f\x7f"\\\\]/', $value) || preg_match('/\s/', $value) || mb_strlen($value) > 200) {
            throw new Error("Invalid value for " . $field['label'] . " (no spaces nor quotes)", 400);
        }
        if ($value !== '' && $field['choices'] && !in_array($value, array_column($field['choices'], 'value'), true)) {
            throw new Error("Invalid choice for " . $field['label'], 400);
        }
        if ($value !== '' && !self::matchesFormat($field['regexp'], $value)) {
            throw new Error("Invalid format for " . $field['label'], 400);
        }
    }

    /**
     * Whether a value matches the format of a woob setting (a format that is not a
     * valid regular expression is ignored).
     *
     * @param string|null $regexp The format of the setting (no delimiters)
     * @param string      $value  The value
     * @return bool
     */
    private static function matchesFormat(?string $regexp, string $value): bool
    {
        if (!$regexp) {
            return true;
        }
        $pattern = '/' . str_replace('/', '\\/', $regexp) . '/u';
        return @preg_match($pattern, '') === false || preg_match($pattern, $value) === 1;
    }

    /**
     * Accounts available in the configured woob backends (or in one of them), to follow
     * them in one click.
     *
     * @param string|null $backend Only the accounts of this woob backend (bank connection),
     *                             else of every configured one
     * @return list<array<string, mixed>> The accounts (bankId, account_number, bank_name, label,
     *               balance, currency, followed)
     * @throws Error If the backend name is invalid
     * @throws Exception If woob fails
     */
    #[ApiRoute('/bank/discover', method: 'GET')]
    public static function discover(?string $backend = null): array
    {
        User::requireAdmin();
        $backend = $backend === '' ? null : $backend;
        if ($backend !== null && !preg_match('/^[a-z0-9_-]{1,50}$/', $backend)) {
            throw new Error("Invalid bank name (lowercase letters, digits, - and _)", 400);
        }
        $followed = array_column(self::accounts(), 'bankId');

        $accounts = [];
        foreach (Woob::listAccounts($backend) as $account) {
            $at = strrpos($account['id'], '@');
            if ($at === false) {
                continue;
            }
            $bankId = $account['id'];
            $accounts[] = [
                'bankId' => $bankId,
                'account_number' => substr($bankId, 0, $at),
                'bank_name' => substr($bankId, $at + 1),
                'label' => $account['label'] ?? null,
                'balance' => $account['balance'] ?? null,
                'currency' => $account['currency'] ?? null,
                'followed' => in_array($bankId, $followed, true),
            ];
        }

        return $accounts;
    }

    /**
     * Save the transactions of a bank account: coming (card transactions not debited
     * yet, type 12) or history.
     *
     * @param string $kind        "coming" or "history"
     * @param string $bankId      The bank id to sync (account_number@bank_name)
     * @param bool   $interactive Allow the bank to ask a two-factor authentication
     * @param bool   $debug       Send the debug logs of woob (woob_debug) in the progress
     * @return list<array<string, mixed>> The new transactions
     */
    private static function syncBankData(string $kind, string $bankId, bool $interactive = false, bool $debug = false): array
    {
        $transactions = self::getBankData($kind, $bankId, $interactive, $debug);
        if (empty($transactions)) {
            Logger::warn("No $kind transactions for $bankId");
            Webservice::sendProgress("Done $bankId ($kind): 0 received, 0 new");
            return [];
        }
        if ($kind === 'coming') {
            $transactions = array_filter($transactions, fn($transaction) => $transaction['type'] == 12);
        }

        [$accountNumber, $bankName] = explode('@', $bankId, 2);
        $created = Transaction::save($transactions, self::getBankOwner($bankId), true,
            ['bank_name' => $bankName, 'account_number' => $accountNumber]);
        Webservice::sendProgress("Done $bankId ($kind): " . count($transactions) . " received, " . count($created) . " new");
        return $created;
    }

    /**
     * Check that the caller may start a synchronization:
     * either an authenticated user (JWT) or the scheduler with the sync_token
     * (X-Sync-Token header or ?token= parameter).
     *
     * @param string|null $token The sync token passed as query parameter
     * @return bool True for an authenticated user, false for the scheduler
     * @throws Error If the caller is not allowed
     */
    private static function checkSyncAccess(?string $token): bool
    {
        $token = Webservice::getHeader('X-Sync-Token') ?? $token;

        if ($token !== null && Config::has('sync_token') && hash_equals((string)Config::get('sync_token'), $token)) {
            return false;
        }

        if (Jwt::checkAuthorization()) {
            return true;
        }

        throw new Error("Unauthorized - Invalid or missing JWT token or sync token", 401);
    }

    /**
     * Synchronize the bank data with Server-Sent Events progress.
     *
     * In interactive mode (a user in the portal, one account), woob may start a
     * two-factor authentication: its messages ("validate in your bank app") are sent
     * as "Bank message <account>: <message>" while it waits for the validation.
     *
     * With woob_debug enabled, an administrator also receives the debug logs of woob
     * (error output) as "Woob debug <account>: <line>", to diagnose a failing bank.
     *
     * The missing categories are only updated when at least one account was synchronized:
     * after a failure, only the users are notified of the error.
     *
     * @param string|null $token       The sync token (for the scheduler)
     * @param string|null $account     Synchronize only this account (account_number@bank_name)
     * @param bool|string|null $interactive "true": allow a two-factor authentication (needs
     *                                      $account); parsed as a boolean ("false" is false)
     * @return void
     * @throws Error If unauthorized, the account is not followed or the interactive mode not allowed
     */
    #[ApiRoute('/bank/sync', method: 'GET', public: true, stream: true)]
    public static function getSync(?string $token = null, ?string $account = null, bool|string|null $interactive = null): void
    {
        $user = self::checkSyncAccess($token);

        // A two-factor authentication is only started for a user who waits for it
        // (never by the scheduler), on one account
        $interactive = filter_var($interactive, FILTER_VALIDATE_BOOLEAN);
        if ($interactive && (!$user || $account === null || $account === '')) {
            throw new Error("The interactive mode needs a user and one account", 400);
        }

        $bankIds = self::syncedBankIds($account);

        // Allow unlimited execution time for bank sync
        set_time_limit(0);

        // The debug logs of woob may show bank data: only for an administrator
        $debug = $user && Config::get('woob_debug') && User::isAdmin();

        // Acquire exclusive lock to prevent concurrent syncs
        $lockAcquired = Db::query("SELECT GET_LOCK('bank_sync', 0) AS locked")->fetch_assoc()['locked'];
        if (!$lockAcquired) {
            Webservice::sendProgress("Synchronization already in progress");
            return;
        }

        try {
            // At least one account synchronized: the transactions may need categories
            $synced = false;

            // Synchronize each bank account (every one, even after a failure)
            foreach ($bankIds as $bankId) {
                $synced = self::syncAccount($bankId, $interactive, $debug) || $synced;
            }

            self::finishSync($synced);
        } finally {
            Db::query("SELECT RELEASE_LOCK('bank_sync')");
        }
    }

    /**
     * Accounts to synchronize: the given one (if followed), else every followed account.
     *
     * @param string|null $account Synchronize only this account (account_number@bank_name)
     * @return list<string> The bank ids
     * @throws Error If the account is not followed
     */
    private static function syncedBankIds(?string $account): array
    {
        $bankIds = self::getBankIds();
        if ($account === null || $account === '') {
            return $bankIds;
        }
        if (!in_array($account, $bankIds, true)) {
            throw new Error("Account not found", 404);
        }
        return [$account];
    }

    /**
     * Synchronize one account (coming then history), record the result and notify the
     * household (result, large expenses, rules marked "notify").
     *
     * @param string $bankId      The bank id (account_number@bank_name)
     * @param bool   $interactive Allow the bank to ask a two-factor authentication
     * @param bool   $debug       Send the debug logs of woob in the progress
     * @return bool True if the account was synchronized
     */
    private static function syncAccount(string $bankId, bool $interactive, bool $debug): bool
    {
        $status = true;
        $created = [];
        $error = null;

        try {
            Webservice::sendProgress("Syncing $bankId (coming)...");
            $created = self::syncBankData('coming', $bankId, $interactive, $debug);

            Webservice::sendProgress("Syncing $bankId (history)...");
            $created = array_merge($created, self::syncBankData('history', $bankId, $interactive, $debug));

        } catch (Exception $e) {
            Logger::error("Error syncing $bankId: " . $e->getMessage());
            Webservice::sendProgress("Error syncing $bankId: " . $e->getMessage());
            $status = false;
            $error = $e->getMessage();
        }
        self::recordSyncResult($bankId, $status, $status ? count($created) . " new" : $error);

        // Send notification to all users who own this bank account
        Webservice::sendProgress("Notifying users of $bankId...");
        self::notifyBankUsers($bankId, $status, $error);
        self::alertLargeExpenses($bankId, $created);
        self::alertRuleMatches($created);

        return $status;
    }

    /**
     * End of a synchronization: categories of the new transactions, cleanup of the old
     * logs, then the completion event.
     *
     * @param bool $synced At least one account was synchronized
     * @return void
     */
    private static function finishSync(bool $synced): void
    {
        // Update missing categories: useless when every account failed (no transaction)
        if ($synced) {
            Webservice::sendProgress("Updating missing categories...");
            Transaction::updateMissingCategories();
        }

        // Delete the log files older than the retention: a cleanup must not fail the sync
        try {
            $deleted = Logger::purge();
            if ($deleted) {
                Webservice::sendProgress(count($deleted) . " old log file(s) deleted");
            }
        } catch (Throwable $e) {
            Logger::error("Cleanup of the logs failed: " . $e->getMessage());
        }

        // Send final completion event
        Webservice::sendProgress("Synchronization complete");
    }
    

    /**
     * Get the user owning a bank account (the first one if the account is shared).
     *
     * @param string $bankId The bank ID (account_number@bank_name)
     * @return int The user id
     * @throws Exception If the bank account has no owner
     */
    private static function getBankOwner(string $bankId): int
    {
        list($accountNumber, $bankName) = explode('@', $bankId, 2);

        $sql = "SELECT MIN(user_id) AS user_id FROM bank_account WHERE account_number = ? AND bank_name = ?";
        $row = Db::queryOne($sql, "ss", $accountNumber, $bankName);

        if (!$row || $row['user_id'] === null) {
            throw new Exception("No owner found for bank account $bankId");
        }

        return (int)$row['user_id'];
    }

    /**
     * Store the date and result of the last synchronization of an account.
     *
     * @param string      $bankId  The account (account_number@bank_name)
     * @param bool        $success True if the synchronization succeeded
     * @param string|null $message The number of new transactions or the error
     * @return void
     */
    private static function recordSyncResult(string $bankId, bool $success, ?string $message): void
    {
        [$number, $bank] = explode('@', $bankId, 2);
        Db::execute("UPDATE bank_account SET last_sync_at = NOW(), last_sync_status = ?, last_sync_message = ? WHERE account_number = ? AND bank_name = ?",
            "ssss", $success ? 'OK' : 'ERROR', mb_substr((string)$message, 0, 500), $number, $bank);
    }

    /**
     * Get all bank IDs from the database.
     *
     * @return list<string> List of bankIds (account_number@bank_name)
     */
    private static function getBankIds(): array
    {
        $sql = "SELECT DISTINCT account_number, bank_name FROM bank_account ORDER BY bank_name, account_number";
        $stmt = Db::execute($sql, "");
        $result = $stmt->get_result();
        
        $bankIds = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $bankIds[] = $row['account_number'] . '@' . $row['bank_name'];
            }
        }
        
        return $bankIds;
    }

    /**
     * Send notification to all users who own a specific bank account.
     *
     * A failure that needs a two-factor authentication asks to approve it in the portal.
     *
     * @param string      $bankId The bank ID (account_number@bank_name)
     * @param bool        $status The sync status (true=success, false=failure)
     * @param string|null $error  The error of a failed synchronization
     * @return void
     */
    public static function notifyBankUsers(string $bankId, bool $status, ?string $error = null): void
    {
        // Parse bankId to extract account_number and bank_name
        list($accountNumber, $bankName) = explode('@', $bankId, 2);
        
        // The accounts are shared by the household: every user is notified
        $stmt = Db::execute("SELECT id AS user_id FROM users", "");
        $result = $stmt->get_result();
        
        // Send notification to each user
        while ($row = $result->fetch_assoc()) {
            $userId = $row['user_id'];
            
            try {
                // Count unpointed transactions of the household
                $unpointed = Transaction::countUnpointed();

                // Determine notification message based on sync status
                if ($status === false && Woob::needsTwoFactor($error)) {
                    $message = "Strong authentication needed";
                    $description = "Open Carbure (Accounts) to approve the connection to " . strtoupper($bankName);
                } elseif ($status === false) {
                    $message = "Bank synchronization failed !";
                    $description = "$unpointed transactions to review";
                } else {
                    $message = "Bank synchronization successful";
                    $description = "$unpointed transactions to review";
                }
                
                // Send notification to user
                Device::sendNotification(
                    $userId,
                    $message,
                    $description,
                    $unpointed
                );

                Logger::info("Notification sent to user $userId for bank $bankId with $unpointed unpointed transactions");
            } catch (Exception $e) {
                Logger::warn("Failed to send notification to user $userId: " . $e->getMessage());
            }
        }
    }

    /**
     * Notify the household of the new transactions matching a rule marked
     * "notify" (one notification per transaction and user).
     *
     * @param array<array<string, mixed>> $transactions The new transactions (label, amount)
     * @return int The number of notifications sent
     */
    public static function alertRuleMatches(array $transactions): int
    {
        if (empty($transactions)) {
            return 0;
        }
        $rules = Db::execute("SELECT keyword FROM bank_transaction_category_keyword WHERE notify = 1", "")->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$rules) {
            return 0;
        }

        // The household is notified: every user
        $users = Db::execute("SELECT id, language FROM users", "")->get_result()->fetch_all(MYSQLI_ASSOC);
        $sent = 0;
        foreach ($transactions as $transaction) {
            $label = strtoupper((string)$transaction['label']);
            foreach ($rules as $rule) {
                if (strpos($label, $rule['keyword']) === false) {
                    continue;
                }
                $sent += self::notifyRuleMatch($users, $transaction, $rule['keyword']);
                // One notification per transaction, even if several rules match
                break;
            }
        }
        if ($sent > 0) {
            Logger::info("$sent rule notification(s) sent");
        }
        return $sent;
    }

    /**
     * Notify every user of a new transaction matching a rule, in the user's language.
     *
     * @param list<array<string, mixed>> $users       The users (id, language)
     * @param array<string, mixed>       $transaction The transaction (label, amount)
     * @param string                     $keyword     The keyword of the rule
     * @return int The number of notifications sent
     */
    private static function notifyRuleMatch(array $users, array $transaction, string $keyword): int
    {
        foreach ($users as $user) {
            $french = $user['language'] === 'fr';
            $amount = number_format((float)$transaction['amount'], 2, $french ? ',' : '.', $french ? ' ' : ',');
            Device::sendNotification(
                $user['id'],
                ($french ? "Nouvelle transaction : " : "New transaction: ") . $keyword,
                trim($transaction['label']) . " : $amount €",
                Transaction::countUnpointed()
            );
        }
        return count($users);
    }

    /**
     * Push an alert to the owners of a bank account for each new expense whose
     * amount reaches their alert threshold (users.alert_threshold, NULL = disabled).
     *
     * @param string                      $bankId       The bank ID (account_number@bank_name)
     * @param array<array<string, mixed>> $transactions The new transactions (label, amount)
     * @return int The number of alerts sent
     */
    public static function alertLargeExpenses(string $bankId, array $transactions): int
    {
        if (empty($transactions)) {
            return 0;
        }

        list($accountNumber, $bankName) = explode('@', $bankId, 2);
        // The accounts are shared by the household: every user with a threshold is alerted
        $sql = "SELECT id, language, alert_threshold FROM users WHERE alert_threshold > 0";
        $users = Db::execute($sql, "")->get_result()->fetch_all(MYSQLI_ASSOC);

        $sent = 0;
        foreach ($users as $user) {
            foreach ($transactions as $transaction) {
                if ($transaction['amount'] >= 0 || -$transaction['amount'] < (float)$user['alert_threshold']) {
                    continue;
                }

                $french = $user['language'] === 'fr';
                $amount = number_format($transaction['amount'], 2, $french ? ',' : '.', $french ? ' ' : ',');
                Device::sendNotification(
                    $user['id'],
                    $french ? "Dépense importante à vérifier" : "Large expense to check",
                    trim($transaction['label']) . " : $amount €",
                    Transaction::countUnpointed()
                );
                $sent++;
            }
        }

        if ($sent > 0) {
            Logger::info("$sent large expense alert(s) sent for $bankId");
        }
        return $sent;
    }

    /**
     * Call Woob to get the list of transactions from a bank. The system command run is
     * sent in the progress as "Woob command <account>: <command>".
     *
     * @param string $type        The type of transactions to get (coming or history)
     * @param string $bankId      The bank id
     * @param bool   $interactive Allow the bank to ask a two-factor authentication
     * @param bool   $debug       Send the debug logs of woob in the progress
     * @return list<mixed>|null The transaction data or null if no data
     */
    private static function getBankData(string $type, string $bankId, bool $interactive = false, bool $debug = false): ?array
    {
        return Woob::getBankData($type, $bankId, $interactive,
            fn($message) => Webservice::sendProgress("Bank message $bankId: $message"),
            $debug ? fn($line) => Webservice::sendProgress("Woob debug $bankId: $line") : null,
            fn($command) => Webservice::sendProgress("Woob command $bankId: $command"));
    }
}
