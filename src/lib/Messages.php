<?php

/**
 * Messages.php
 *
 * Texts written by the server to a user (notifications pushed or emailed), in the
 * language of his profile (users.language: fr or en, anything else gives fr, as the
 * installation does), and the format of the amounts and dates in that language.
 * Self-contained: the texts of the portal are in portal/i18n.js.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Messages {

    /** Languages of the messages; the first one is the default */
    const LANGUAGES = ['fr', 'en'];

    /**
     * Texts by language and key ({name}: a parameter). A key ending in _one / _other
     * is a plural form (see plural()). Both languages have the same keys and the same
     * parameters (checked by a unit test).
     */
    const TEXTS = [
        'fr' => [
            // Result of a bank synchronization (kind sync)
            'sync_success_title' => 'Synchronisation bancaire réussie',
            'sync_error_title' => 'Échec de la synchronisation bancaire',
            'sync_error_body' => 'Erreur de {bank} : {error}',
            'sync_2fa_title' => 'Authentification forte requise',
            'sync_2fa_body' => 'Ouvrez Carbure (Comptes) pour approuver la connexion à {bank}',
            'sync_review_one' => '{count} transaction à vérifier',
            'sync_review_other' => '{count} transactions à vérifier',
            // New transaction matching a rule marked "notify" (kind rule)
            'rule_title' => 'Nouvelle transaction : {keyword}',
            // New expense above the alert threshold (kind expense)
            'expense_title' => 'Dépense importante à vérifier',
            // A transaction: its label, amount and date (rule, expense)
            'transaction_body' => '{label} : {amount}',
            'transaction_body_dated' => '{label} : {amount} le {date}',
            // Spending of the month of a budget over the threshold of the user (kind budget)
            'budget_exceeded_title' => 'Budget dépassé : {category}',
            'budget_reached_title' => 'Budget presque atteint : {category}',
            'budget_body' => '{spent} sur {budget} ({percent} %)',
            // Emails
            'email_subject' => '[Carbure] {title}',
            'email_footer' => 'Vous recevez cet email selon vos préférences de notification (Mon profil > Notifications).',
            'email_sent_by' => 'Envoyé par Carbure',
            'email_portal' => 'Ouvrir Carbure : {url}',
            'smtp_test_title' => 'Email de test',
            'smtp_test_body' => 'Ce message de test confirme que Carbure peut envoyer les notifications par email.',
        ],
        'en' => [
            'sync_success_title' => 'Bank synchronization successful',
            'sync_error_title' => 'Bank synchronization failed',
            'sync_error_body' => 'Error of {bank}: {error}',
            'sync_2fa_title' => 'Strong authentication needed',
            'sync_2fa_body' => 'Open Carbure (Accounts) to approve the connection to {bank}',
            'sync_review_one' => '{count} transaction to review',
            'sync_review_other' => '{count} transactions to review',
            'rule_title' => 'New transaction: {keyword}',
            'expense_title' => 'Large expense to check',
            'transaction_body' => '{label}: {amount}',
            'transaction_body_dated' => '{label}: {amount} on {date}',
            'budget_exceeded_title' => 'Budget exceeded: {category}',
            'budget_reached_title' => 'Budget almost reached: {category}',
            'budget_body' => '{spent} of {budget} ({percent}%)',
            'email_subject' => '[Carbure] {title}',
            'email_footer' => 'You receive this email according to your notification preferences (My profile > Notifications).',
            'email_sent_by' => 'Sent by Carbure',
            'email_portal' => 'Open Carbure: {url}',
            'smtp_test_title' => 'Test email',
            'smtp_test_body' => 'This test message confirms that Carbure can send the notifications by email.',
        ],
    ];

    /** Short names of the months in English (no locale data needed) */
    private const MONTHS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /**
     * Language of the messages of a user: fr or en, anything else (empty, unknown) fr.
     *
     * @param string|null $language The language of the profile
     * @return string
     */
    public static function language(?string $language): string
    {
        $language = strtolower(trim((string)$language));
        return in_array($language, self::LANGUAGES, true) ? $language : self::LANGUAGES[0];
    }

    /**
     * A text in a language, its parameters replaced.
     *
     * @param string|null          $language The language (see language())
     * @param string               $key      The key
     * @param array<string, mixed> $params   The values of the {parameters}
     * @return string
     * @throws Exception If the key is unknown
     */
    public static function t(?string $language, string $key, array $params = []): string
    {
        $text = self::TEXTS[self::language($language)][$key] ?? null;
        if ($text === null) {
            throw new Exception("Unknown message: $key");
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string)$value;
        }
        return strtr($text, $replace);
    }

    /**
     * A text with a count: <key>_one or <key>_other (French: 0 and 1 are singular;
     * English: only 1), {count} given.
     *
     * @param string|null          $language The language
     * @param string               $key      The key, without _one / _other
     * @param int                  $count    The count
     * @param array<string, mixed> $params   The other parameters
     * @return string
     */
    public static function plural(?string $language, string $key, int $count, array $params = []): string
    {
        $one = self::language($language) === 'fr' ? abs($count) < 2 : abs($count) === 1;
        return self::t($language, $key . ($one ? '_one' : '_other'), ['count' => $count] + $params);
    }

    /**
     * An amount in euros: fr "-1 234,56 €", en "-1,234.56 €".
     *
     * @param string|null $language The language
     * @param float       $amount   The amount
     * @return string
     */
    public static function amount(?string $language, float $amount): string
    {
        $french = self::language($language) === 'fr';
        return number_format($amount, 2, $french ? ',' : '.', $french ? ' ' : ',') . ' €';
    }

    /**
     * A date: fr "15/10/2026", en "Oct 15, 2026"; '' if it cannot be read.
     *
     * @param string|null $language The language
     * @param string      $date     The date (Y-m-d, or any format strtotime() reads)
     * @return string
     */
    public static function date(?string $language, string $date): string
    {
        $time = strtotime($date);
        if ($time === false) {
            return '';
        }
        if (self::language($language) === 'fr') {
            return date('d/m/Y', $time);
        }
        return self::MONTHS_EN[(int)date('n', $time) - 1] . date(' j, Y', $time);
    }

    /**
     * Text of a transaction in a notification: label and amount, and its date when known.
     *
     * @param string|null $language The language
     * @param string      $label    The label
     * @param float       $amount   The amount
     * @param string|null $date     The date (null: not shown)
     * @return string
     */
    public static function transaction(?string $language, string $label, float $amount, ?string $date = null): string
    {
        $params = ['label' => trim($label), 'amount' => self::amount($language, $amount)];
        $date = $date === null ? '' : self::date($language, $date);
        if ($date === '') {
            return self::t($language, 'transaction_body', $params);
        }
        return self::t($language, 'transaction_body_dated', $params + ['date' => $date]);
    }
}
