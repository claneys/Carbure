<?php

/**
 * N26: export CSV of the transactions, current and former (in French) formats.
 *
 * Particularities: ",", every cell between quotes, UTF-8, ISO dates, the name of the
 * partner (or payee) completed by the payment reference, the amount in euros (the
 * amount in a foreign currency is not read). The former export in English or German
 * has no fixture: it is read by the generic reader.
 * Sources: beancount-n26, budgetpilot, servant.
 * Fixtures: tests/fixtures/import/n26.csv, n26-legacy.csv. Schema: BankProfiles.
 */

return [
    'name' => 'N26',
    'formats' => [
        [
            'header' => [
                'Booking Date', 'Value Date', 'Partner Name', 'Partner Iban', 'Type', 'Payment Reference',
                'Account Name', 'Amount (EUR)', 'Original Amount', 'Original Currency', 'Exchange Rate',
            ],
            'delimiter' => ',',
            'columns' => [
                'date' => 'Booking Date', 'label' => 'Partner Name', 'detail' => 'Payment Reference',
                'amount' => 'Amount (EUR)',
            ],
        ],
        [
            'header' => [
                'Date', 'Bénéficiaire', 'Numéro de compte', 'Type de transaction', 'Référence de paiement',
                'Montant (EUR)', 'Montant (Devise étrangère)', 'Sélectionnez la devise étrangère', 'Taux de conversion',
            ],
            'delimiter' => ',',
            'columns' => [
                'date' => 'Date', 'label' => 'Bénéficiaire', 'detail' => 'Référence de paiement',
                'amount' => 'Montant (EUR)',
            ],
        ],
    ],
];
