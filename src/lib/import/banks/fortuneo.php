<?php

/**
 * Fortuneo: HistoriqueOperations….csv.
 *
 * Particularities: ";" with an empty last column, ISO-8859-1 (read as Windows-1252, its
 * superset for the printable characters), negative amounts in "Débit" and positive ones
 * in "Crédit", the operation date as the date, dates dd/mm/yyyy.
 * Sources: beancount-importers (fortuneo), finance-toolkit, Firefly III import
 * configurations (fr/fortuneo).
 * Fixture: tests/fixtures/import/fortuneo.csv. Schema: BankProfiles.
 */

return [
    'name' => 'Fortuneo',
    'formats' => [
        [
            'header' => ['Date opération', 'Date valeur', 'libellé', 'Débit', 'Crédit'],
            'delimiter' => ';',
            'encoding' => 'Windows-1252',
            'day_first' => true,
            'columns' => ['date' => 'Date opération', 'label' => 'libellé', 'debit' => 'Débit', 'credit' => 'Crédit'],
        ],
    ],
];
