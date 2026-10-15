<?php

/**
 * Revolut: statement « Excel » (a CSV file).
 *
 * Particularities: ",", UTF-8, dates with the time, "Completed Date" as the date and
 * "Started Date" as the date of the operation, the fee taken off the amount, the rows
 * whose state is not COMPLETED skipped (and the zero amounts: card authorizations).
 * Sources: ofxstatement-revolut (sample files), budgetpilot.
 * Fixture: tests/fixtures/import/revolut.csv. Schema: BankProfiles.
 */

return [
    'name' => 'Revolut',
    'formats' => [
        [
            'header' => ['Type', 'Product', 'Started Date', 'Completed Date', 'Description', 'Amount', 'Fee', 'Currency', 'State', 'Balance'],
            'delimiter' => ',',
            'columns' => [
                'date' => 'Completed Date', 'operation' => 'Started Date', 'label' => 'Description',
                'amount' => 'Amount', 'fee' => 'Fee', 'status' => 'State',
            ],
            'skip_statuses' => ['PENDING', 'REVERTED', 'DECLINED', 'FAILED', 'CANCELLED'],
        ],
    ],
];
