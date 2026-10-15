<?php

/**
 * BNP Paribas / Hello bank!: export CSV of the operations.
 *
 * Particularities: summary lines (account, date, balance) before the header, ";",
 * Windows-1252, dates dd-mm-yyyy, the category columns not read. An older export
 * separated by "," has no fixture: it is read by the generic reader.
 * Sources: bnp2ynab, Mosa, budget-forecaster.
 * Fixture: tests/fixtures/import/bnp-paribas.csv. Schema: BankProfiles.
 */

return [
    'name' => 'BNP Paribas / Hello bank!',
    'formats' => [
        [
            'header' => ['Date operation', 'Categorie operation', 'Sous Categorie operation', 'Libelle operation', 'Montant operation'],
            'delimiter' => ';',
            'preamble' => 3,
            'encoding' => 'Windows-1252',
            'day_first' => true,
            'columns' => ['date' => 'Date operation', 'label' => 'Libelle operation', 'amount' => 'Montant operation'],
        ],
    ],
];
