<?php

use Carbure\Lib\Month;
use PHPUnit\Framework\TestCase;

class MonthTest extends TestCase
{
    public function testResolveDefaultsToTheCurrentMonth()
    {
        $current = [(int)date('m'), (int)date('Y')];
        $this->assertSame($current, Month::resolve());
        $this->assertSame($current, Month::resolve('', 0));
        $this->assertSame([3, (int)date('Y')], Month::resolve('03'));
    }

    public function testResolveCastsTheValues()
    {
        // Query string values are strings, sometimes with garbage
        $this->assertSame([1, 2020], Month::resolve('1) OR (1=1', '2020'));
    }

    public function testFirst()
    {
        $this->assertSame('2026-02-01', Month::first(2, 2026));
        $this->assertSame(date('Y-m-01'), Month::first());
    }

    public function testRange()
    {
        $this->assertSame(['2026-02-01', '2026-03-01'], Month::range(2, 2026));
        // December: the next month is in the next year
        $this->assertSame(['2025-12-01', '2026-01-01'], Month::range('12', '2025'));
    }

    public function testTimestampOfARelativeDate()
    {
        $this->assertSame('2026-11-01', date('Y-m-d', Month::timestamp('2026-10-01 +1 month')));
        $this->assertSame('2026-11-01', date('Y-m-d', Month::timestamp('first day of next month', Month::timestamp('2026-10-15'))));
    }

    public function testTimestampOfAnInvalidDateThrows()
    {
        // Never false given to date(): 1970-01-01
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid date: not a date');
        Month::timestamp('not a date');
    }
}
