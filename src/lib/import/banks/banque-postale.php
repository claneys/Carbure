<?php

/**
 * La Banque Postale: « Téléchargement des opérations », format CSV.
 *
 * Particularities: lines about the account (number, type, currency, date, balances)
 * before the header, ";", Windows-1252, CRLF, the amount in euros "Montant(EUROS)" (the
 * amount in francs is not read), dates dd/mm/yyyy.
 * Sources: bookiemoney (in/csv/bankpostale.yml), servant, comptes-chateau.
 * Fixture: tests/fixtures/import/banque-postale.csv. Schema: BankProfiles.
 */

return [
    'name' => 'La Banque Postale',
    'formats' => [
        [
            'header' => ['Date', 'Libellé', 'Montant(EUROS)', 'Montant(FRANCS)'],
            'delimiter' => ';',
            'preamble' => 10,
            'encoding' => 'Windows-1252',
            'day_first' => true,
            'columns' => ['date' => 'Date', 'label' => 'Libellé', 'amount' => 'Montant(EUROS)'],
        ],
    ],
];
