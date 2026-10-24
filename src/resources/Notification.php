<?php

/**
 * Notification.php
 *
 * The notifications of the household, sent on the channels chosen by each user for
 * each kind of notification: push to the devices of the user (iOS app, through the push
 * service Carbure Push: API key of the configuration file) and email to the address of
 * his profile (SMTP server of the configuration file). Without a
 * choice, a kind is pushed and not emailed (table user_notification, no row), except
 * the budget alerts, off on every channel (opt-in).
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
use Carbure\Lib\Mailer;
use Carbure\Lib\Messages;
use Carbure\Lib\PushRelay;
use Error;
use Exception;
use Throwable;

final class Notification {

    /**
     * Kinds of notification: result of a synchronization, new transaction matching a
     * rule marked "notify", large expense (alert threshold of the user), spending of a
     * budget over the budget threshold of the user (BudgetAlert)
     */
    const KINDS = ['sync', 'rule', 'expense', 'budget'];

    /** Channels of a notification, and whether each one is on without a choice of the user */
    const DEFAULTS = ['push' => true, 'email' => false];

    /** Kinds off on every channel without a choice of the user (opt-in) */
    const OPT_IN = ['budget'];

    /** Values of a channel accepted by PUT /user/notifications (JSON or query string) */
    private const FLAGS = ['true' => true, '1' => true, 'false' => false, '0' => false];

    /**
     * Channels of each kind of notification of the authenticated user, whether the
     * emails can be sent to him and the settings of his alerts.
     *
     * @return array<string, mixed> preferences ({kind: {push, email}}), smtp (an SMTP server
     *               is configured), address (email of the profile, null if none),
     *               email_available (smtp and address), push_available (an API key of the
     *               push service is configured), budget_threshold (percent of a budget
     *               reached to alert, kind budget), alert_threshold (amount of a large
     *               expense, kind expense, null: off), budget_all (the global budget
     *               threshold is on: all the budgets), budget_thresholds (thresholds of
     *               categories, [{category, threshold}], used instead of the global one),
     *               budget_category (for the older clients: the only category of
     *               budget_thresholds when the global threshold is off, else null),
     *               budgets (the expense categories with a budget this month, [{id, name}],
     *               and those of budget_thresholds)
     */
    #[ApiRoute('/user/notifications', method: 'GET')]
    public static function get(): array
    {
        return self::describe(Jwt::getUserIdFromToken());
    }

    /**
     * Change the channels of kinds of notification of the authenticated user and the
     * settings of his alerts; the kinds, channels and settings not sent are kept.
     *
     * @param array<mixed>          $preferences     {kind: {push?, email?}}: kind sync, rule, expense
     *                                               or budget, each channel true, false, "true",
     *                                               "false", 1 or 0 (optional)
     * @param int|float|string|null $budgetThreshold Percent of a budget whose spending alerts
     *                                               (kind budget): integer from 50 to 200 (optional)
     * @param int|float|string|null $alertThreshold  Amount of a large expense (kind expense): a
     *                                               positive amount, "" or 0 turns it off (optional)
     * @param int|string|null       $budgetCategory  Older clients: the only budget watched (an
     *                                               expense category, the global threshold off),
     *                                               or 0 / "" for all the budgets; not with
     *                                               budgetThresholds (optional)
     * @param bool|int|string|null  $budgetAll       The global threshold (budgetThreshold) is on,
     *                                               for all the budgets: true, false, "true",
     *                                               "false", 1 or 0 (optional)
     * @param array<mixed>|null     $budgetThresholds Thresholds of categories, replacing the
     *                                               previous ones: [{category, threshold}], an
     *                                               expense category each, 50 to 200 (optional)
     * @return array<string, mixed> The preferences, as GET /user/notifications
     * @throws Error If a kind, a channel, a value or a setting is not valid (400), or a
     *               setting is sent before its migration is applied (409)
     */
    #[ApiRoute('/user/notifications', method: 'PUT')]
    public static function update(array $preferences = [], int|float|string|null $budgetThreshold = null,
        int|float|string|null $alertThreshold = null, int|string|null $budgetCategory = null,
        bool|int|string|null $budgetAll = null, ?array $budgetThresholds = null): array
    {
        $userId = Jwt::getUserIdFromToken();
        // Checked before anything is saved
        $fields = self::settingFields($budgetThreshold, $alertThreshold, $budgetCategory);
        $rules = self::rulesChange($userId, $budgetAll, $budgetThresholds, $budgetCategory, $budgetThreshold);
        $current = self::preferences($userId);
        foreach ($preferences as $kind => $channels) {
            $current[self::checkKind((string)$kind)] = array_merge($current[$kind], self::checkChannels((string)$kind, $channels));
        }

        self::saveSettings($userId, $fields);
        if ($rules !== null) {
            BudgetAlert::saveRules($userId, $rules['all'], $rules['categories']);
        }
        foreach ($current as $kind => $channels) {
            Db::execute("INSERT INTO user_notification (user_id, kind, push, email) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE push = VALUES(push), email = VALUES(email)", "isii",
                $userId, $kind, (int)$channels['push'], (int)$channels['email']);
        }
        return self::describe($userId);
    }

    /**
     * Columns of the user to update with the settings sent, checked.
     *
     * @param int|float|string|null $budgetThreshold Percent of a budget (null: not sent)
     * @param int|float|string|null $alertThreshold  Amount of a large expense (null: not sent)
     * @param int|string|null       $budgetCategory  Category watched (null: not sent)
     * @return array<string, array{0: string, 1: int|float|null}> By column: type and value
     * @throws Error If a setting is not valid (400)
     */
    private static function settingFields(int|float|string|null $budgetThreshold,
        int|float|string|null $alertThreshold, int|string|null $budgetCategory): array
    {
        $fields = [];
        if ($budgetThreshold !== null) {
            $fields['budget_threshold'] = ['i', BudgetAlert::thresholdValue($budgetThreshold)];
        }
        if ($alertThreshold !== null) {
            $fields['alert_threshold'] = ['d', User::alertThresholdValue($alertThreshold)];
        }
        if ($budgetCategory !== null) {
            $fields['budget_alert_category'] = ['i', BudgetAlert::categoryValue($budgetCategory)];
        }
        return $fields;
    }

    /**
     * New thresholds of the budget alerts of a user, checked: null when none is sent, or
     * before the migration 2026-10-27_budget_alert_threshold for an older client (its
     * budget watched is then saved in budget_alert_category only).
     *
     * @param int                   $userId           The user
     * @param bool|int|string|null  $budgetAll        Global threshold on (null: not sent)
     * @param array<mixed>|null     $budgetThresholds Thresholds of categories (null: not sent)
     * @param int|string|null       $budgetCategory   Budget watched of the older clients (null: not sent)
     * @param int|float|string|null $budgetThreshold  Global threshold (null: not sent)
     * @return array{all: bool, categories: array<int, int>}|null
     * @throws Error If a value is not valid (400), or a new setting is sent before its migration (409)
     */
    private static function rulesChange(int $userId, bool|int|string|null $budgetAll, ?array $budgetThresholds,
        int|string|null $budgetCategory, int|float|string|null $budgetThreshold): ?array
    {
        if ($budgetAll === null && $budgetThresholds === null && $budgetCategory === null) {
            return null;
        }
        if ($budgetThresholds !== null && $budgetCategory !== null) {
            throw new Error("Send either budgetThresholds or budgetCategory", 400);
        }
        if (!BudgetAlert::thresholdsReady()) {
            if ($budgetCategory === null) {
                throw new Error("Apply the pending database migrations first: the budget alert thresholds cannot be saved yet", 409);
            }
            return null;
        }
        return $budgetCategory !== null
            ? self::watchedChange($userId, $budgetCategory, $budgetThreshold)
            : self::thresholdsChange($userId, $budgetAll, $budgetThresholds);
    }

    /**
     * Thresholds sent by a client that knows the thresholds of categories; what is not
     * sent is kept.
     *
     * @param int                  $userId           The user
     * @param bool|int|string|null $budgetAll        Global threshold on (null: not sent)
     * @param array<mixed>|null    $budgetThresholds Thresholds of categories (null: not sent)
     * @return array{all: bool, categories: array<int, int>}
     * @throws Error If a value is not valid (400)
     */
    private static function thresholdsChange(int $userId, bool|int|string|null $budgetAll, ?array $budgetThresholds): array
    {
        $current = BudgetAlert::rules($userId);
        return [
            'all' => $budgetAll === null ? $current['all'] !== null : self::flag('budget', 'budgetAll', $budgetAll),
            'categories' => $budgetThresholds === null ? $current['categories'] : BudgetAlert::thresholdsValue($budgetThresholds),
        ];
    }

    /**
     * Thresholds of the budget watched sent by an older client: the global threshold for
     * all the budgets, or only that category at the threshold sent (or the current one).
     *
     * @param int                   $userId          The user
     * @param int|string            $budgetCategory  An expense category, or 0 / "" for all the budgets
     * @param int|float|string|null $budgetThreshold Threshold sent with it (null: not sent)
     * @return array{all: bool, categories: array<int, int>}
     * @throws Error If a value is not valid (400)
     */
    private static function watchedChange(int $userId, int|string $budgetCategory, int|float|string|null $budgetThreshold): array
    {
        $category = BudgetAlert::categoryValue($budgetCategory);
        if ($category === null) {
            return ['all' => true, 'categories' => []];
        }
        $threshold = $budgetThreshold === null ? self::budgetThreshold($userId) : BudgetAlert::thresholdValue($budgetThreshold);
        return ['all' => false, 'categories' => [$category => $threshold]];
    }

    /**
     * Save the settings of the alerts of a user.
     *
     * @param int                                                $userId The user
     * @param array<string, array{0: string, 1: int|float|null}> $fields By column: type and value
     * @return void
     * @throws Error If a column is missing: its migration is not applied yet (409)
     */
    private static function saveSettings(int $userId, array $fields): void
    {
        foreach ($fields as $column => [$type, $value]) {
            try {
                Db::execute("UPDATE users SET $column = ? WHERE id = ?", $type . "i", $value, $userId);
            } catch (Exception $e) {
                throw new Error("Apply the pending database migrations first: the settings of the alerts cannot be saved yet", 409);
            }
        }
    }

    /**
     * Channels of each kind of notification of a user (the defaults for a kind he
     * never chose).
     *
     * @param int $userId The user
     * @return array<string, array{push: bool, email: bool}> By kind
     */
    public static function preferences(int $userId): array
    {
        $preferences = array_combine(self::KINDS, array_map([self::class, 'defaults'], self::KINDS));
        $rows = Db::select("SELECT kind, push, email FROM user_notification WHERE user_id = ?", "i", $userId)->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            if (isset($preferences[$row['kind']])) {
                $preferences[$row['kind']] = ['push' => (bool)$row['push'], 'email' => (bool)$row['email']];
            }
        }
        return $preferences;
    }

    /**
     * Channels of a kind without a choice of the user: push only, or none for an
     * opt-in kind.
     *
     * @param string $kind The kind
     * @return array{push: bool, email: bool}
     */
    public static function defaults(string $kind): array
    {
        return in_array($kind, self::OPT_IN, true) ? ['push' => false, 'email' => false] : self::DEFAULTS;
    }

    /**
     * Send a notification to a user on the channels he chose for its kind. A channel
     * that fails is logged and does not stop the other one (nor the synchronization).
     *
     * @param int    $userId The user
     * @param string $kind   sync, rule, expense or budget
     * @param string $title  Title (subject of the email)
     * @param string $body   Text
     * @param int    $badge  Badge of the app icon (push)
     * @return array{push: bool, email: bool} The channels the notification was sent on
     */
    public static function notify(int $userId, string $kind, string $title, string $body, int $badge = 0): array
    {
        $channels = self::channels($userId, $kind);
        return [
            'push' => $channels['push'] && self::push($userId, $title, $body, $badge),
            'email' => $channels['email'] && self::email($userId, $title, $body),
        ];
    }

    /**
     * Channels of a kind for a user; the defaults when they cannot be read (database
     * not migrated yet): the notifications are still pushed (not the opt-in kinds).
     *
     * @param int    $userId The user
     * @param string $kind   The kind
     * @return array{push: bool, email: bool}
     */
    private static function channels(int $userId, string $kind): array
    {
        try {
            return self::preferences($userId)[$kind] ?? self::defaults($kind);
        } catch (Exception $e) {
            Logger::warn("Notification preferences of user $userId unreadable, defaults used: " . $e->getMessage());
            return self::defaults($kind);
        }
    }

    /**
     * Push a notification to the devices of a user.
     *
     * @param int    $userId The user
     * @param string $title  Title
     * @param string $body   Text
     * @param int    $badge  Badge of the app icon
     * @return bool Whether it was pushed (false: no API key of the push service, or failed, logged)
     */
    private static function push(int $userId, string $title, string $body, int $badge): bool
    {
        if (!PushRelay::isConfigured()) {
            Logger::info("Notification of user $userId not pushed: no API key of the push service (push_api_key)");
            return false;
        }
        try {
            Device::sendNotification($userId, $title, $body, $badge);
            return true;
        } catch (Throwable $e) {
            Logger::warn("Push notification to user $userId failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Email a notification to the address of a user, in his language.
     *
     * @param int    $userId The user
     * @param string $title  Title (subject)
     * @param string $body   Text
     * @return bool Whether it was sent (false: no SMTP server or no address, or failed, logged)
     */
    private static function email(int $userId, string $title, string $body): bool
    {
        $user = Db::queryOne("SELECT email, language FROM users WHERE id = ?", "i", $userId);
        if (!Mailer::isConfigured() || empty($user['email'])) {
            Logger::info("Notification of user $userId not emailed: no SMTP server or no email address");
            return false;
        }
        try {
            self::sendEmail((string)$user['email'], (string)$user['language'], $title, $body);
            return true;
        } catch (Throwable $e) {
            Logger::warn("Email notification to user $userId failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Email a message in a language: subject "[Carbure] <title>", the title and the text,
     * then who sent it, the link to the portal (when public_url is set) and, for a
     * notification, where to change the choice.
     *
     * @param string      $to       Address of the recipient
     * @param string|null $language The language of the recipient (see Messages::language())
     * @param string      $title    Title, already in that language
     * @param string      $body     Text, already in that language
     * @param bool        $footer   Tell where the notifications are chosen
     * @return void
     * @throws Error If the email cannot be sent (see Mailer::send())
     */
    public static function sendEmail(string $to, ?string $language, string $title, string $body, bool $footer = true): void
    {
        Mailer::send($to, Messages::t($language, 'email_subject', ['title' => $title]), self::emailText($language, $title, $body, $footer));
    }

    /**
     * Text of an email: the message, then a signature in the same language.
     *
     * @param string|null $language The language of the recipient
     * @param string      $title    Title
     * @param string      $body     Text
     * @param bool        $footer   Tell where the notifications are chosen
     * @return string
     */
    public static function emailText(?string $language, string $title, string $body, bool $footer = true): string
    {
        $lines = [Messages::t($language, 'email_sent_by')];
        if (Config::has('public_url')) {
            $lines[] = Messages::t($language, 'email_portal', ['url' => OAuth::baseUrl() . '/portal/']);
        }
        if ($footer) {
            $lines[] = Messages::t($language, 'email_footer');
        }
        return "$title\n\n$body\n\n-- \n" . implode("\n", $lines) . "\n";
    }

    /**
     * The preferences of a user, as GET /user/notifications.
     *
     * @param int $userId The user
     * @return array<string, mixed>
     */
    private static function describe(int $userId): array
    {
        $user = Db::queryOne("SELECT email, alert_threshold FROM users WHERE id = ?", "i", $userId);
        $rules = BudgetAlert::rules($userId);
        $thresholds = [];
        foreach ($rules['categories'] as $category => $threshold) {
            $thresholds[] = ['category' => $category, 'threshold' => $threshold];
        }
        $address = empty($user['email']) ? null : (string)$user['email'];
        $smtp = Mailer::isConfigured();
        return [
            'preferences' => self::preferences($userId),
            'smtp' => $smtp,
            'address' => $address,
            'email_available' => $smtp && $address !== null,
            'push_available' => PushRelay::isConfigured(),
            'budget_threshold' => self::budgetThreshold($userId),
            'alert_threshold' => $user === null || $user['alert_threshold'] === null ? null : (float)$user['alert_threshold'],
            'budget_all' => $rules['all'] !== null,
            'budget_thresholds' => $thresholds,
            'budget_category' => BudgetAlert::watchedOf($rules),
            'budgets' => self::budgets(array_keys($rules['categories'])),
        ];
    }

    /**
     * Budgets that the budget alerts can watch: the expense categories with a budget
     * this month, and the categories with a threshold that have none this month.
     *
     * @param list<int> $watched The categories with a threshold
     * @return list<array{id: int, name: string}>
     */
    private static function budgets(array $watched): array
    {
        $budgets = BudgetAlert::budgets((int)date('n'), (int)date('Y'));
        foreach (array_diff($watched, array_column($budgets, 'id')) as $id) {
            $row = Db::queryOne("SELECT name FROM bank_transaction_category WHERE id = ?", "i", $id);
            $budgets[] = ['id' => $id, 'name' => (string)($row['name'] ?? $id)];
        }
        return $budgets;
    }

    /**
     * Budget alert threshold of a user, in percent; the default when it cannot be read
     * (database not migrated yet).
     *
     * @param int $userId The user
     * @return int
     */
    private static function budgetThreshold(int $userId): int
    {
        try {
            $row = Db::queryOne("SELECT budget_threshold FROM users WHERE id = ?", "i", $userId);
        } catch (Exception $e) {
            return BudgetAlert::DEFAULT_THRESHOLD;
        }
        return $row === null ? BudgetAlert::DEFAULT_THRESHOLD : (int)$row['budget_threshold'];
    }

    /**
     * Check a kind of notification.
     *
     * @param string $kind The kind
     * @return string The kind
     * @throws Error If it is not a known kind (400)
     */
    private static function checkKind(string $kind): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new Error("Unknown notification kind: $kind (" . implode(', ', self::KINDS) . ")", 400);
        }
        return $kind;
    }

    /**
     * Check the channels of a kind.
     *
     * @param string $kind     The kind
     * @param mixed  $channels {push?, email?}
     * @return array<string, bool> The channels sent, by name
     * @throws Error If a channel or a value is not valid (400)
     */
    private static function checkChannels(string $kind, mixed $channels): array
    {
        if (!is_array($channels)) {
            throw new Error("The channels of $kind must be an object {push, email}", 400);
        }
        $checked = [];
        foreach ($channels as $channel => $value) {
            if (!array_key_exists($channel, self::DEFAULTS)) {
                throw new Error("Unknown notification channel: $channel (push, email)", 400);
            }
            $checked[$channel] = self::flag($kind, (string)$channel, $value);
        }
        return $checked;
    }

    /**
     * A channel on or off: true, false, "true", "false", 1, 0, "1" or "0".
     *
     * @param string $kind    The kind
     * @param string $channel The channel
     * @param mixed  $value   The value sent
     * @return bool
     * @throws Error If it is not one of these values (400)
     */
    private static function flag(string $kind, string $channel, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $key = is_int($value) || is_string($value) ? strtolower((string)$value) : null;
        if ($key === null || !isset(self::FLAGS[$key])) {
            throw new Error("Invalid value for $kind.$channel: true or false expected", 400);
        }
        return self::FLAGS[$key];
    }
}
