<?php

/**
 * Crédit Agricole: export CSV of the operations.
 *
 * Particularities: lines about the account (name, number, balance) before the header,
 * ";", Windows-1252, positive amounts in "Débit euros" and "Crédit euros", dates
 * dd/mm/yyyy. Fixture: tests/fixtures/import/credit-agricole.csv. Schema: BankProfiles.
 */

return [
    'name' => 'Crédit Agricole',
    'formats' => [
        [
            'header' => ['Date', 'Libellé', 'Débit euros', 'Crédit euros'],
            'delimiter' => ';',
            'preamble' => 10,
            'encoding' => 'Windows-1252',
            'day_first' => true,
            'columns' => ['date' => 'Date', 'label' => 'Libellé', 'debit' => 'Débit euros', 'credit' => 'Crédit euros'],
        ],
    ],
];
