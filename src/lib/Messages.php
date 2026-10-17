<?php

/**
 * Messages.php
 *
 * Texts written by the server to a user (notifications pushed or emailed), in the
 * language of his profile (users.language: fr, en, es, it, de or pt; anything else gives
 * fr, as the installation does), and the format of the amounts and dates in that language.
 * Self-contained: the texts of the portal are in portal/i18n.js.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Exception;

final class Messages {

    /** Languages of the messages; the first one is the default */
    const LANGUAGES = ['fr', 'en', 'es', 'it', 'de', 'pt'];

    /**
     * Texts by language and key ({name}: a parameter). A key ending in _one / _other
     * is a plural form (see plural()). Every language has the keys and the parameters of
     * French (checked by a unit test).
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
            'push_test_title' => 'Notification de test',
            'push_test_body' => 'Cette notification de test confirme que Carbure peut envoyer les notifications push.',
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
            'push_test_title' => 'Test notification',
            'push_test_body' => 'This test notification confirms that Carbure can send the push notifications.',
        ],
        'es' => [
            'sync_success_title' => 'Sincronización bancaria completada',
            'sync_error_title' => 'Error en la sincronización bancaria',
            'sync_error_body' => 'Error de {bank}: {error}',
            'sync_2fa_title' => 'Autenticación reforzada necesaria',
            'sync_2fa_body' => 'Abre Carbure (Cuentas) para aprobar la conexión con {bank}',
            'sync_review_one' => '{count} transacción por revisar',
            'sync_review_other' => '{count} transacciones por revisar',
            'rule_title' => 'Nueva transacción: {keyword}',
            'expense_title' => 'Gasto importante por revisar',
            'transaction_body' => '{label}: {amount}',
            'transaction_body_dated' => '{label}: {amount} el {date}',
            'budget_exceeded_title' => 'Presupuesto superado: {category}',
            'budget_reached_title' => 'Presupuesto casi alcanzado: {category}',
            'budget_body' => '{spent} de {budget} ({percent} %)',
            'email_subject' => '[Carbure] {title}',
            'email_footer' => 'Recibes este correo según tus preferencias de notificación (Mi perfil > Notificaciones).',
            'email_sent_by' => 'Enviado por Carbure',
            'email_portal' => 'Abrir Carbure: {url}',
            'smtp_test_title' => 'Correo de prueba',
            'smtp_test_body' => 'Este mensaje de prueba confirma que Carbure puede enviar las notificaciones por correo.',
            'push_test_title' => 'Notificación de prueba',
            'push_test_body' => 'Esta notificación de prueba confirma que Carbure puede enviar las notificaciones push.',
        ],
        'it' => [
            'sync_success_title' => 'Sincronizzazione bancaria riuscita',
            'sync_error_title' => 'Sincronizzazione bancaria non riuscita',
            'sync_error_body' => 'Errore di {bank}: {error}',
            'sync_2fa_title' => 'Autenticazione forte richiesta',
            'sync_2fa_body' => 'Apri Carbure (Conti) per approvare la connessione a {bank}',
            'sync_review_one' => '{count} transazione da verificare',
            'sync_review_other' => '{count} transazioni da verificare',
            'rule_title' => 'Nuova transazione: {keyword}',
            'expense_title' => 'Spesa importante da verificare',
            'transaction_body' => '{label}: {amount}',
            'transaction_body_dated' => '{label}: {amount} il {date}',
            'budget_exceeded_title' => 'Budget superato: {category}',
            'budget_reached_title' => 'Budget quasi raggiunto: {category}',
            'budget_body' => '{spent} su {budget} ({percent}%)',
            'email_subject' => '[Carbure] {title}',
            'email_footer' => 'Ricevi questa email in base alle tue preferenze di notifica (Il mio profilo > Notifiche).',
            'email_sent_by' => 'Inviato da Carbure',
            'email_portal' => 'Apri Carbure: {url}',
            'smtp_test_title' => 'Email di prova',
            'smtp_test_body' => 'Questo messaggio di prova conferma che Carbure può inviare le notifiche via email.',
            'push_test_title' => 'Notifica di prova',
            'push_test_body' => 'Questa notifica di prova conferma che Carbure può inviare le notifiche push.',
        ],
        'de' => [
            'sync_success_title' => 'Banksynchronisierung erfolgreich',
            'sync_error_title' => 'Banksynchronisierung fehlgeschlagen',
            'sync_error_body' => 'Fehler von {bank}: {error}',
            'sync_2fa_title' => 'Starke Authentifizierung erforderlich',
            'sync_2fa_body' => 'Öffnen Sie Carbure (Konten), um die Verbindung zu {bank} zu bestätigen',
            'sync_review_one' => '{count} Transaktion zu prüfen',
            'sync_review_other' => '{count} Transaktionen zu prüfen',
            'rule_title' => 'Neue Transaktion: {keyword}',
            'expense_title' => 'Hohe Ausgabe zu prüfen',
            'transaction_body' => '{label}: {amount}',
            'transaction_body_dated' => '{label}: {amount} am {date}',
            'budget_exceeded_title' => 'Budget überschritten: {category}',
            'budget_reached_title' => 'Budget fast erreicht: {category}',
            'budget_body' => '{spent} von {budget} ({percent} %)',
            'email_subject' => '[Carbure] {title}',
            'email_footer' => 'Sie erhalten diese E-Mail gemäß Ihren Benachrichtigungseinstellungen (Mein Profil > Benachrichtigungen).',
            'email_sent_by' => 'Gesendet von Carbure',
            'email_portal' => 'Carbure öffnen: {url}',
            'smtp_test_title' => 'Test-E-Mail',
            'smtp_test_body' => 'Diese Testnachricht bestätigt, dass Carbure Benachrichtigungen per E-Mail senden kann.',
            'push_test_title' => 'Testbenachrichtigung',
            'push_test_body' => 'Diese Testbenachrichtigung bestätigt, dass Carbure Push-Benachrichtigungen senden kann.',
        ],
        'pt' => [
            'sync_success_title' => 'Sincronização bancária concluída',
            'sync_error_title' => 'Falha na sincronização bancária',
            'sync_error_body' => 'Erro de {bank}: {error}',
            'sync_2fa_title' => 'Autenticação forte necessária',
            'sync_2fa_body' => 'Abra o Carbure (Contas) para aprovar a ligação a {bank}',
            'sync_review_one' => '{count} transação por verificar',
            'sync_review_other' => '{count} transações por verificar',
            'rule_title' => 'Nova transação: {keyword}',
            'expense_title' => 'Despesa elevada a verificar',
            'transaction_body' => '{label}: {amount}',
            'transaction_body_dated' => '{label}: {amount} em {date}',
            'budget_exceeded_title' => 'Orçamento excedido: {category}',
            'budget_reached_title' => 'Orçamento quase atingido: {category}',
            'budget_body' => '{spent} de {budget} ({percent} %)',
            'email_subject' => '[Carbure] {title}',
            'email_footer' => 'Recebe este email de acordo com as suas preferências de notificação (O meu perfil > Notificações).',
            'email_sent_by' => 'Enviado pelo Carbure',
            'email_portal' => 'Abrir o Carbure: {url}',
            'smtp_test_title' => 'Email de teste',
            'smtp_test_body' => 'Esta mensagem de teste confirma que o Carbure consegue enviar as notificações por email.',
            'push_test_title' => 'Notificação de teste',
            'push_test_body' => 'Esta notificação de teste confirma que o Carbure consegue enviar as notificações push.',
        ],
    ];

    /** Short names of the months in English (no locale data needed) */
    private const MONTHS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Separators of the amounts by language: [decimal, thousands] */
    private const AMOUNT_SEPARATORS = [
        'fr' => [',', ' '],
        'en' => ['.', ','],
        'es' => [',', '.'],
        'it' => [',', '.'],
        'de' => [',', '.'],
        'pt' => [',', ' '],
    ];

    /** Format of the dates by language (date()); English: see date() */
    private const DATE_FORMATS = ['fr' => 'd/m/Y', 'es' => 'd/m/Y', 'it' => 'd/m/Y', 'de' => 'd.m.Y', 'pt' => 'd/m/Y'];

    /**
     * Language of the messages of a user: one of LANGUAGES, anything else (empty, unknown) fr.
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
     * the other languages: only 1), {count} given.
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
     * An amount in euros: fr/pt "-1 234,56 €", en "-1,234.56 €", es/it/de "-1.234,56 €".
     *
     * @param string|null $language The language
     * @param float       $amount   The amount
     * @return string
     */
    public static function amount(?string $language, float $amount): string
    {
        [$decimal, $thousands] = self::AMOUNT_SEPARATORS[self::language($language)];
        return number_format($amount, 2, $decimal, $thousands) . ' €';
    }

    /**
     * A date: fr/es/it/pt "15/10/2026", de "15.10.2026", en "Oct 15, 2026"; '' if it
     * cannot be read.
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
        $format = self::DATE_FORMATS[self::language($language)] ?? null;
        if ($format !== null) {
            return date($format, $time);
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
