<?php

/**
 * Caisse d'Épargne / Banque Populaire (BPCE): export CSV of the operations.
 *
 * Particularities: ";", 13 columns, the full "Libelle operation" (not the short "Libelle
 * simplifie") completed by "Informations complementaires", "Date de comptabilisation" as
 * the date and "Date operation" as the date of the operation, amounts in "Debit"
 * (negative) and "Credit", dates dd/mm/yyyy.
 * Sources: budgetpilot, Djoulzy/compta, CashCrawler.
 * Fixture: tests/fixtures/import/caisse-epargne.csv. Schema: BankProfiles.
 */

return [
    'name' => "Caisse d'Épargne / Banque Populaire",
    'formats' => [
        [
            'header' => [
                'Date de comptabilisation', 'Libelle simplifie', 'Libelle operation', 'Reference',
                'Informations complementaires', 'Type operation', 'Categorie', 'Sous categorie', 'Debit', 'Credit',
                'Date operation', 'Date de valeur', 'Pointage operation',
            ],
            'delimiter' => ';',
            'encoding' => 'Windows-1252',
            'day_first' => true,
            'columns' => [
                'date' => 'Date de comptabilisation', 'operation' => 'Date operation', 'label' => 'Libelle operation',
                'detail' => 'Informations complementaires', 'debit' => 'Debit', 'credit' => 'Credit',
            ],
        ],
    ],
];
