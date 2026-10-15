<?php

/**
 * Société Générale: « Télécharger les opérations », format CSV.
 *
 * Particularities: a line about the account (="number", period, balance) before the
 * header, ";", Windows-1252, CRLF, the label completed by "Détail de l'écriture" (the
 * detail alone when it already holds the label), dates dd/mm/yyyy.
 * Sources: moneymanager (readers/societe_generale.py), reckon (fixture).
 * Fixture: tests/fixtures/import/societe-generale.csv. Schema: BankProfiles.
 */

return [
    'name' => 'Société Générale',
    'formats' => [
        [
            'header' => ["Date de l'opération", 'Libellé', "Détail de l'écriture", "Montant de l'opération", 'Devise'],
            'delimiter' => ';',
            'preamble' => 3,
            'encoding' => 'Windows-1252',
            'day_first' => true,
            'columns' => [
                'date' => "Date de l'opération", 'label' => 'Libellé', 'detail' => "Détail de l'écriture",
                'amount' => "Montant de l'opération",
            ],
        ],
    ],
];
