<?php

use Carbure\Resources\Transaction;
use PHPUnit\Framework\TestCase;

class TransactionTest extends TestCase
{
    public function testCleanLabelCardPayment()
    {
        $this->assertSame('CB AMAZON', Transaction::cleanLabel('facture carte du 010126 amazon carte 4974XXXXXXXX1234'));
    }

    public function testCleanLabelDirectDebit()
    {
        $this->assertSame('PRLV SEPA EDF ', Transaction::cleanLabel('PRLV SEPA EDF MDT/123'));

        // Known limitation: the greedy regex only removes the last part (see TODO.md).
        // Changing it would change the labels, thus the transaction UUIDs.
        $this->assertSame('PRLV SEPA EDF ECH/010126 ', Transaction::cleanLabel('PRLV SEPA EDF ECH/010126 MDT/123'));
    }

    public function testUuidIgnoresCardLabelSuffix()
    {
        $uuid = new ReflectionMethod(Transaction::class, 'generateTransactionUuid');

        // Card payments: only the first 24 chars of the label are significant
        $a = $uuid->invoke(null, 7, '2026-01-01', 'CB SUPERMARCHE DU CENTRE VILLE', -12.5);
        $b = $uuid->invoke(null, 12, '2026-01-01', 'CB SUPERMARCHE DU CENTRE', -12.5);
        $this->assertSame($a, $b);

        // Other transactions: the whole label is significant
        $c = $uuid->invoke(null, 1, '2026-01-01', 'VIR SALAIRE JANVIER', 2000);
        $d = $uuid->invoke(null, 1, '2026-01-01', 'VIR SALAIRE JANVIER BIS', 2000);
        $this->assertNotSame($c, $d);
    }

    public function testIdenticalTransactionsHaveTheirOwnUuid()
    {
        $coffee = ['date' => '2026-01-05', 'amount' => -2.5, 'raw' => 'PRLV CAFE DU COIN', 'type' => 2];
        $other = ['date' => '2026-01-05', 'amount' => -4, 'raw' => 'PRLV BOULANGERIE', 'type' => 2];
        $rows = Transaction::prepareAll(['a' => $coffee, 'b' => $other, 'c' => $coffee, 'd' => $coffee]);

        // The first one keeps the UUID of a single transaction: no UUID of the database changes
        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($rows));
        $this->assertSame(Transaction::prepare($coffee)['uuid'], $rows['a']['uuid']);
        $this->assertSame(Transaction::prepare($other)['uuid'], $rows['b']['uuid']);
        $this->assertSame(md5($rows['a']['uuid'] . '#1'), $rows['c']['uuid']);
        $this->assertSame(md5($rows['a']['uuid'] . '#2'), $rows['d']['uuid']);

        // Received again in the next synchronization: the same UUIDs
        $this->assertSame($rows, Transaction::prepareAll(['a' => $coffee, 'b' => $other, 'c' => $coffee, 'd' => $coffee]));
    }

    public function testCardPaymentsWithTheSameLabelStartKeepTheirUuid()
    {
        // Same first 24 chars: the order of the bank does not change which UUID each one gets
        $north = ['date' => '2026-01-05', 'rdate' => '2026-01-04', 'amount' => -20, 'raw' => 'CB STATION SERVICE AUTOROUTE NORD', 'type' => 7];
        $south = ['date' => '2026-01-05', 'rdate' => '2026-01-04', 'amount' => -20, 'raw' => 'CB STATION SERVICE AUTOROUTE SUD', 'type' => 7];
        $uuids = fn($rows) => array_column($rows, 'uuid', 'label');

        $first = $uuids(Transaction::prepareAll([$north, $south]));
        $this->assertCount(2, array_unique($first));
        $this->assertEquals($first, $uuids(Transaction::prepareAll([$south, $north])));
    }

    public function testSalaryAtEndOfMonthMovesToNextMonth()
    {
        $fix = new ReflectionMethod(Transaction::class, 'fixPrelevementDate');

        $this->assertSame('2026-02-01', $fix->invoke(null, 1, 2000, '2026-01-28'));
        $this->assertSame('2026-01-10', $fix->invoke(null, 1, 2000, '2026-01-10'));
        $this->assertSame('2026-01-28', $fix->invoke(null, 7, -20, '2026-01-28'));
    }

    /**
     * Transactions of the months before October 2026 (and of October)
     * @param array $series [label, amount, day, months ago...] (months ago: 0 = October);
     *                      card payment (type 7) when the label starts with "CB ", else direct debit
     * @return array Rows, oldest first, as read from the database
     */
    private function recurringRows($series)
    {
        $rows = [];
        $id = 1;
        foreach ($series as [$label, $amount, $day, $ago]) {
            foreach ((array)$ago as $months) {
                $date = date('Y-m-d', strtotime("2026-10-01 -$months months +" . ($day - 1) . ' days'));
                $type = str_starts_with($label, 'CB ') ? 7 : 2;
                $rows[] = ['id' => $id++, 'date' => $date, 'type' => $type, 'label' => $label, 'category' => 3, 'amount' => $amount];
            }
        }
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']) ?: $a['id'] <=> $b['id']);
        return $rows;
    }

    public function testFindRecurring()
    {
        $rows = $this->recurringRows([
            // Monthly, received in October; the reference changes every month
            ['PRLV SEPA EDF MDT/1234', -80, 5, [3, 2, 1]],
            ['PRLV SEPA EDF MDT/1299', -120, 6, [0]],
            // Monthly, not received yet in October
            ['CB NETFLIX.COM', -13.49, 20, [3, 2, 1]],
            // Twice: not enough months
            ['PRLV GYM CLUB', -30, 10, [1, 0]],
            // Every month, but many times (groceries)
            ['CB CARREFOUR', -50, 3, [3, 2, 1, 0]],
            ['CB CARREFOUR', -60, 12, [3, 2, 1, 0]],
            // Every month, at any day
            ['CB AMAZON', -25, 2, [3, 1]],
            ['CB AMAZON', -25, 18, [2, 0]],
            // Card payment every month at the same day, but not the same amount (restaurant)
            ['CB SUSHI SHOP', -20, 12, [3, 1]],
            ['CB SUSHI SHOP', -55, 12, [2, 0]],
            // Stopped two months ago
            ['PRLV ANCIEN ABONNEMENT', -9.99, 8, [5, 4, 3, 2]],
            // Same label, debit and credit: two series
            ['VIR COMPTE JOINT', -400, 27, [3, 2, 1]],
            ['VIR COMPTE JOINT', 400, 27, [3, 2, 1]],
        ]);

        $series = Transaction::findRecurring($rows, '2026-10-01');

        $this->assertSame(['PRLV SEPA EDF MDT/1299', 'CB NETFLIX.COM', 'VIR COMPTE JOINT', 'VIR COMPTE JOINT'], array_column($series, 'label'));
        [$edf, $netflix, $debit, $credit] = $series;

        $this->assertSame('received', $edf['status']);
        $this->assertCount(1, $edf['ids']);
        $this->assertSame(-120.0, $edf['amount']);
        $this->assertSame(-90.0, $edf['average']);
        $this->assertTrue($edf['variable']);
        $this->assertSame(4, $edf['months']);
        $this->assertSame('2026-10-06', $edf['last']);

        $this->assertSame('expected', $netflix['status']);
        $this->assertSame([], $netflix['ids']);
        $this->assertSame(20, $netflix['day']);
        $this->assertFalse($netflix['variable']);
        $this->assertSame('2026-09-20', $netflix['last']);

        $this->assertSame([-400.0, 400.0], [$debit['amount'], $credit['amount']]);
    }

    public function testRecurringAroundTheEndOfTheMonth()
    {
        // Paid on the 30th or on the 1st: the same usual day
        $rows = $this->recurringRows([
            ['PRLV LOYER', -950, 30, [4, 2]],
            ['PRLV LOYER', -950, 1, [3, 1, 0]],
        ]);

        $series = Transaction::findRecurring($rows, '2026-10-01');

        $this->assertCount(1, $series);
        $this->assertSame(5, $series[0]['months']);
    }
}
