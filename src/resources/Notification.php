<?php

/**
 * Notification.php
 *
 * The notifications of the household, sent on the channels chosen by each user for
 * each kind of notification: push to the devices of the user (iOS app) and email to
 * the address of his profile (SMTP server of the configuration file). Without a
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
     *               email_available (smtp and address), budget_threshold (percent of a
     *               budget reached to alert, kind budget), alert_threshold (amount of a
     *               large expense, kind expense, null: off), budget_category (category
     *               watched by the budget alerts, null: all the budgets), budgets (the
     *               expense categories with a budget this month, [{id, name}], and the one
     *               watched)
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
     * @param int|string|null       $budgetCategory  Category watched by the budget alerts: an
     *                                               expense category, 0 or "" for all the budgets
     *                                               (optional)
     * @return array<string, mixed> The preferences, as GET /user/notifications
     * @throws Error If a kind, a channel, a value or a setting is not valid (400), or the
     *               budget watched is sent before its migration is applied (409)
     */
    #[ApiRoute('/user/notifications', method: 'PUT')]
    public static function update(array $preferences = [], int|float|string|null $budgetThreshold = null,
        int|float|string|null $alertThreshold = null, int|string|null $budgetCategory = null): array
    {
        $userId = Jwt::getUserIdFromToken();
        // Checked before anything is saved
        $fields = self::settingFields($budgetThreshold, $alertThreshold, $budgetCategory);
        $current = self::preferences($userId);
        foreach ($preferences as $kind => $channels) {
            $current[self::checkKind((string)$kind)] = array_merge($current[$kind], self::checkChannels((string)$kind, $channels));
        }

        self::saveSettings($userId, $fields);
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
        $rows = Db::execute("SELECT kind, push, email FROM user_notification WHERE user_id = ?", "i", $userId)
            ->get_result()->fetch_all(MYSQLI_ASSOC);
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
     * @return bool Whether it was pushed (false: failed, logged)
     */
    private static function push(int $userId, string $title, string $body, int $badge): bool
    {
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
        $category = BudgetAlert::watchedCategory($userId);
        $address = empty($user['email']) ? null : (string)$user['email'];
        $smtp = Mailer::isConfigured();
        return [
            'preferences' => self::preferences($userId),
            'smtp' => $smtp,
            'address' => $address,
            'email_available' => $smtp && $address !== null,
            'budget_threshold' => self::budgetThreshold($userId),
            'alert_threshold' => $user === null || $user['alert_threshold'] === null ? null : (float)$user['alert_threshold'],
            'budget_category' => $category,
            'budgets' => self::budgets($category),
        ];
    }

    /**
     * Budgets that the budget alerts can watch: the expense categories with a budget
     * this month, and the category watched when it has none.
     *
     * @param int|null $watched The category watched (null: all the budgets)
     * @return list<array{id: int, name: string}>
     */
    private static function budgets(?int $watched): array
    {
        $budgets = BudgetAlert::budgets((int)date('n'), (int)date('Y'));
        if ($watched !== null && !in_array($watched, array_column($budgets, 'id'), true)) {
            $row = Db::queryOne("SELECT name FROM bank_transaction_category WHERE id = ?", "i", $watched);
            $budgets[] = ['id' => $watched, 'name' => (string)($row['name'] ?? $watched)];
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
