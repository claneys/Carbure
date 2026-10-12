<?php

/**
 * Month.php
 *
 * Month of a request: the month and year given to the API, the current month by default.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Month {

    /**
     * Month and year, the current ones when not given (null, '' or 0).
     *
     * @param int|string|null $month The month (1 to 12)
     * @param int|string|null $year  The year
     * @return array{int, int} [month, year]
     */
    public static function resolve(int|string|null $month = null, int|string|null $year = null): array
    {
        return [(int)($month ?: date('m')), (int)($year ?: date('Y'))];
    }

    /**
     * First day of the month (Y-m-d).
     *
     * @param int|string|null $month The month (current month by default)
     * @param int|string|null $year  The year (current year by default)
     * @return string The first day
     */
    public static function first(int|string|null $month = null, int|string|null $year = null): string
    {
        [$month, $year] = self::resolve($month, $year);
        return sprintf('%04d-%02d-01', $year, $month);
    }

    /**
     * Dates of the month as a range: date >= from AND date < to (uses the index on the dates).
     *
     * @param int|string|null $month The month (current month by default)
     * @param int|string|null $year  The year (current year by default)
     * @return array{string, string} [from: first day of the month, to: first day of the next month]
     */
    public static function range(int|string|null $month = null, int|string|null $year = null): array
    {
        $from = self::first($month, $year);
        return [$from, date('Y-m-d', strtotime("$from +1 month"))];
    }
}
