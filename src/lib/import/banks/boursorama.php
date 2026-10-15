<?php

/**
 * Boursorama / BoursoBank: export CSV of the movements of an account.
 *
 * Particularities: ";", UTF-8, signed "amount" column, the operation date "dateOp"
 * used as the date of the transaction (the value date "dateVal" is not read).
 * Fixture: tests/fixtures/import/boursorama.csv. Schema: BankProfiles.
 */

return [
    'name' => 'Boursorama / BoursoBank',
    'formats' => [
        [
            'header' => ['dateOp', 'dateVal', 'label', 'category', 'categoryParent', 'supplierFound', 'amount',
                         'comment', 'accountNum', 'accountLabel', 'accountbalance'],
            'delimiter' => ';',
            'columns' => ['date' => 'dateOp', 'label' => 'label', 'amount' => 'amount'],
        ],
    ],
];
