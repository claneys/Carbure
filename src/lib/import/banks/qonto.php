<?php

/**
 * Qonto: export CSV of the transactions, in English or in French.
 *
 * Particularities: English export ",", French export ";" with dates dd-mm-yyyy hh:mm:ss,
 * UTF-8, the name of the counterparty completed by the reference, the total amount
 * (incl. VAT), the rows not executed skipped (pending, declined, reversed...). The
 * English export is booked on the settlement date, the French one on the operation date.
 * Source: demo-coda-export (a single source: to confirm with a real file).
 * Fixtures: tests/fixtures/import/qonto.csv, qonto-fr.csv. Schema: BankProfiles.
 */

return [
    'name' => 'Qonto',
    'formats' => [
        [
            'header' => [
                'Status', 'Settlement date (UTC)', 'Operation date (UTC)', 'Total amount (incl. VAT)', 'Currency',
                'Counterparty name', 'Payment method', 'Transaction ID', 'IBAN', 'Reference', 'Category',
            ],
            'delimiter' => ',',
            'columns' => [
                'date' => 'Settlement date (UTC)', 'operation' => 'Operation date (UTC)', 'label' => 'Counterparty name',
                'detail' => 'Reference', 'amount' => 'Total amount (incl. VAT)', 'status' => 'Status',
            ],
            'skip_statuses' => ['pending', 'declined', 'reversed', 'canceled', 'cancelled', 'failed'],
        ],
        [
            'header' => [
                'Statut', 'Date de la valeur (UTC)', "Date de l'opération (UTC)", 'Montant total (TTC)', 'Débit', 'Crédit',
                'Solde', 'Devise', 'Nom de la contrepartie', 'IBAN de la contrepartie', 'Méthode de paiement',
                'Identifiant de transaction', 'Référence', 'Catégorie de trésorerie',
            ],
            'delimiter' => ';',
            'day_first' => true,
            'columns' => [
                'date' => "Date de l'opération (UTC)", 'label' => 'Nom de la contrepartie', 'detail' => 'Référence',
                'amount' => 'Montant total (TTC)', 'status' => 'Statut',
            ],
            'skip_statuses' => ['En attente', 'En cours', 'Refusé', 'Annulé', 'Rejeté'],
        ],
    ],
];
