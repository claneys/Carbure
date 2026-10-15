<?php

/**
 * Crédit Mutuel / CIC: export CSV of the operations.
 *
 * Particularities: ";", ISO-8859-15, CRLF, negative amounts in "Débit" and positive ones
 * in "Crédit", the value date and the balance not read, dates dd/mm/yyyy.
 * Sources: beancount-importers (ccm), afup/web (fixture).
 * Fixture: tests/fixtures/import/credit-mutuel.csv. Schema: BankProfiles.
 */

return [
    'name' => 'Crédit Mutuel / CIC',
    'formats' => [
        [
            'header' => ['Date', 'Date de valeur', 'Débit', 'Crédit', 'Libellé', 'Solde'],
            'delimiter' => ';',
            'encoding' => 'ISO-8859-15',
            'day_first' => true,
            'columns' => ['date' => 'Date', 'label' => 'Libellé', 'debit' => 'Débit', 'credit' => 'Crédit'],
        ],
    ],
];
