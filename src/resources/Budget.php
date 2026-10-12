<?php

/**
 * Budget.php
 *
 * Budget class to calculate the budget consumption by category.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Budget {
    
    /**
     * Get the current budget consumption by category.
     *
     * The budget of a category with sub-categories is its own amount when one is set
     * (an override), otherwise the sum of the budgets of its sub-categories.
     *
     * @param int|string|null $month    The month to get the budget
     * @param int|string|null $year     The year to get the budget
     * @param int|string|null $category The parent category id
     * @return list<array<string, mixed>> The list of budget items; besides budget, consummed and progress:
     *               budget_mode ('own' or 'children': sum of the sub-categories),
     *               children (number of sub-categories) and children_budget (their sum)
     */
    #[ApiRoute('/budget', method: 'GET')]
    public static function get(int|string|null $month = null, int|string|null $year = null, int|string|null $category = 0): array
    {
        [$from, $to] = Month::range($month, $year);

        // Total of the month for each category, in one pass
        $sql = "SELECT category, SUM(amount) AS total FROM bank_transaction
                WHERE date >= ? AND date < ? GROUP BY category";
        $totals = array_column(Db::execute($sql, "ss", $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC), 'total', 'category');

        // Sub-categories of each category
        $children = self::children();

        // Budgets of the month by category
        $budgets = self::budgets($from, $to);

        // Categories of the requested level with their budget of the month
        $sql = "SELECT c.id, c.name, c.type, c.icon, c.color, IFNULL(b.amount, 0) AS budget
                FROM bank_transaction_category c
                LEFT JOIN budget b ON b.category = c.id AND b.date >= ? AND b.date < ?
                WHERE c.parent_category = ?";
        $categories = Db::execute($sql, "ssi", $from, $to, $category)->get_result()->fetch_all(MYSQLI_ASSOC);

        // Consumption of a category = its transactions + those of its sub-categories
        $budget = [];
        foreach ($categories as $row) {
            $total = null;
            foreach (array_merge([$row['id']], $children[$row['id']] ?? []) as $id) {
                if (isset($totals[$id])) {
                    $total = ($total ?? 0) + (float)$totals[$id];
                }
            }

            // Own budget, or the sum of the budgets of the sub-categories
            $kids = $children[$row['id']] ?? [];
            $childrenBudget = round(array_sum(array_map(fn($id) => $budgets[$id] ?? 0, $kids)), 2);
            $mode = self::budgetMode((float)$row['budget'], count($kids));
            if ($mode === 'children') {
                $row['budget'] = number_format($childrenBudget, 2, '.', '');
            }
            $amount = (float)$row['budget'];
            $row['budget_mode'] = $mode;
            $row['children'] = count($kids);
            $row['children_budget'] = $childrenBudget;
            $row['consummed'] = $total === null ? 0 : round(abs($total), 2);
            $row['progress'] = ($total === null || $amount == 0) ? 0 : (int)abs(round($total / $amount * 100));
            $budget[] = $row;
        }

        // Most consumed first
        usort($budget, fn($a, $b) => $b['consummed'] <=> $a['consummed']);

        return $budget;
    }

    /**
     * Sub-categories of each category.
     *
     * @return array<int|string, list<int|string>> [parent id => [child ids]]
     */
    private static function children(): array
    {
        $children = [];
        $sql = "SELECT id, parent_category FROM bank_transaction_category WHERE parent_category <> 0 AND id <> parent_category";
        foreach (Db::execute($sql, "")->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $children[$row['parent_category']][] = $row['id'];
        }
        return $children;
    }

    /**
     * Budget amounts of a month by category.
     *
     * @param string $from First day of the month
     * @param string $to   First day of the next month
     * @return array<int|string, float> [category id => amount]
     */
    private static function budgets(string $from, string $to): array
    {
        $sql = "SELECT category, amount FROM budget WHERE date >= ? AND date < ?";
        return array_map('floatval', array_column(Db::execute($sql, "ss", $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC), 'amount', 'category'));
    }

    /**
     * How the budget of a category is computed: its own amount, or the sum of the
     * budgets of its sub-categories when it has some and no amount of its own.
     *
     * @param float $amount   Own budget amount of the category (0 when not set)
     * @param int   $children Number of sub-categories
     * @return string 'own' or 'children'
     */
    private static function budgetMode(float $amount, int $children): string
    {
        return $children > 0 && $amount == 0 ? 'children' : 'own';
    }

    /**
     * Set the budget for a category. For a category with sub-categories, the amount
     * overrides the sum of their budgets; 0 goes back to that sum.
     *
     * @param int              $category The category to set the budget
     * @param int|float|string $amount   The amount of the budget (a number or a numeric string)
     * @param int|string|null  $month    The month of the budget
     * @param int|string|null  $year     The year of the budget
     * @return bool True on success
     */
    #[ApiRoute('/budget', method: 'POST')]
    public static function create(int $category, int|float|string $amount, int|string|null $month = null, int|string|null $year = null): bool
    {
        // Insert or update the budget for the given category and month
        $date = Month::first($month, $year);
        $sql = "INSERT INTO budget (date, amount, category) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE amount=?";

        Db::execute($sql, "sdid", $date, $amount, $category, $amount);

        return true;
    }   


    /**
     * Get the monthly trends of the household over the last months.
     *
     * For each month (oldest first):
     * - debit:     expenses, off-budget categories excluded (positive amount)
     * - credit:    incomes, off-budget categories excluded
     * - offBudget: net amount of the off-budget categories (e.g. transfers to savings)
     * - planned:   sum of the budgets of the top-level categories (own amount, or
     *              the sum of their sub-categories)
     * - savings:   money put aside: net amount moved to the savings category
     *              ("Epargne" by default, setting savings_category) and its
     *              sub-categories, positive when saved
     *
     * @param int|string|null $months The number of months (1 to 60)
     * @param int|string|null $offset Number of months between the current month and the last month
     *                                of the period (0 = up to the current month, used for comparisons)
     * @return list<array<string, mixed>> The list of months
     */
    #[ApiRoute('/budget/trends', method: 'GET')]
    public static function getTrends(int|string|null $months = 24, int|string|null $offset = 0): array
    {
        $months = max(1, min(60, (int)$months));
        $offset = max(0, min(120, (int)$offset));
        $last = date('Y-m-01', strtotime(date('Y-m-01') . " -$offset months"));
        $from = date('Y-m-01', strtotime("$last -" . ($months - 1) . ' months'));
        $to = date('Y-m-01', strtotime("$last +1 month"));

        // Empty months are kept with zero values
        $trends = [];
        for ($i = 0; $i < $months; $i++) {
            $month = date('Y-m', strtotime("$from +$i months"));
            $trends[$month] = ['month' => $month, 'debit' => 0, 'credit' => 0, 'offBudget' => 0, 'planned' => 0, 'savings' => 0];
        }

        $sql = "SELECT DATE_FORMAT(t.date, '%Y-%m') AS month,
                SUM(CASE WHEN IFNULL(c.type, '') <> 'HORS-BUDGET' AND t.amount < 0 THEN -t.amount ELSE 0 END) AS debit,
                SUM(CASE WHEN IFNULL(c.type, '') <> 'HORS-BUDGET' AND t.amount > 0 THEN t.amount ELSE 0 END) AS credit,
                SUM(CASE WHEN c.type = 'HORS-BUDGET' THEN t.amount ELSE 0 END) AS offBudget,
                -SUM(CASE WHEN FIND_IN_SET(t.category, ?) THEN t.amount ELSE 0 END) AS savings
            FROM bank_transaction t
            LEFT JOIN bank_transaction_category c ON c.id = t.category
            WHERE t.date >= ? AND t.date < ?
            GROUP BY month";
        $savings = implode(',', self::savingsCategories());
        foreach (Db::execute($sql, "sss", $savings, $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            foreach (['debit', 'credit', 'offBudget', 'savings'] as $key) {
                $trends[$row['month']][$key] = round((float)$row[$key], 2);
            }
        }

        // Budget of the top-level categories: their own amount, or the sum of their sub-categories
        $top = [];
        foreach (Db::execute("SELECT id, parent_category FROM bank_transaction_category WHERE id <> 0", "")->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
            $parent = (int)$c['parent_category'];
            $top[(int)$c['id']] = ($parent === 0 || $parent === (int)$c['id']) ? 0 : $parent;
        }
        $own = [];
        $children = [];
        $sql = "SELECT DATE_FORMAT(date, '%Y-%m') AS month, category, amount FROM budget WHERE date >= ? AND date < ?";
        foreach (Db::execute($sql, "ss", $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $id = (int)$row['category'];
            if (!isset($top[$id])) {
                continue;
            }
            if ($top[$id] === 0) {
                $own[$row['month']][$id] = (float)$row['amount'];
            } else {
                $children[$row['month']][$top[$id]] = ($children[$row['month']][$top[$id]] ?? 0) + (float)$row['amount'];
            }
        }
        $hasChildren = array_count_values(array_filter($top));
        foreach (array_keys($trends) as $month) {
            $planned = 0.0;
            foreach ($top as $id => $parent) {
                if ($parent !== 0) {
                    continue;
                }
                $amount = $own[$month][$id] ?? 0.0;
                $planned += self::budgetMode($amount, $hasChildren[$id] ?? 0) === 'children' ? ($children[$month][$id] ?? 0) : $amount;
            }
            $trends[$month]['planned'] = round($planned, 2);
        }

        return array_values($trends);
    }

    /**
     * Money flow of a month, for the flow diagram of the Budget tab: where the
     * income comes from and where it goes. Amounts are grouped by top-level
     * category; the off-budget categories (internal transfers) are left out,
     * except the savings category.
     *
     * @param int|string|null $month The month (current month by default)
     * @param int|string|null $year  The year (current year by default)
     * @return array<string, mixed> month, income [{id, name, color, icon, amount}], expenses [...],
     *               savings (net amount put aside), totalIncome, totalExpenses, balance
     */
    #[ApiRoute('/budget/flow', method: 'GET')]
    public static function flow(int|string|null $month = null, int|string|null $year = null): array
    {
        [$month, $year] = Month::resolve($month, $year);
        [$categories, $rows] = self::flowRows($month, $year);

        $income = [];
        $expenses = [];
        $saved = 0.0;
        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $top = (int)$row['top'];
            if ($row['kind'] === 'savings') {
                $saved -= $amount;
            } elseif ($row['kind'] === 'income') {
                $income[$top] = ($income[$top] ?? 0) + $amount;
            } else {
                $expenses[$top] = ($expenses[$top] ?? 0) - $amount;
            }
        }

        $nodes = function (array $amounts) use ($categories): array {
            $list = [];
            foreach ($amounts as $id => $amount) {
                if (round($amount, 2) <= 0) {
                    continue;
                }
                $c = $categories[$id] ?? ['name' => null, 'color' => '', 'icon' => ''];
                $list[] = ['id' => $id, 'name' => $c['name'], 'color' => $c['color'], 'icon' => $c['icon'], 'amount' => round($amount, 2)];
            }
            usort($list, fn($a, $b) => $b['amount'] <=> $a['amount']);
            return $list;
        };

        $income = $nodes($income);
        $expenses = $nodes($expenses);
        $totalIncome = round(array_sum(array_column($income, 'amount')), 2);
        $totalExpenses = round(array_sum(array_column($expenses, 'amount')), 2);
        $saved = round($saved, 2);

        return [
            'month' => sprintf('%04d-%02d', $year, $month),
            'income' => $income,
            'expenses' => $expenses,
            'savings' => $saved,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            // What is left once the expenses are paid and the savings put aside
            'balance' => round($totalIncome - $totalExpenses - max($saved, 0), 2),
        ];
    }

    /**
     * Transactions behind a node of the money flow diagram, most recent first.
     *
     * @param string          $kind       income, expense or savings
     * @param int|string|null $categories Top-level category ids, comma separated (all by default)
     * @param int|string|null $month      The month (current month by default)
     * @param int|string|null $year       The year (current year by default)
     * @return list<array<string, mixed>> The transactions, with their top-level category (top)
     * @throws Error If the kind is unknown
     */
    #[ApiRoute('/budget/flow/transactions', method: 'GET')]
    public static function flowTransactions(string $kind, int|string|null $categories = null, int|string|null $month = null, int|string|null $year = null): array
    {
        if (!in_array($kind, ['income', 'expense', 'savings'], true)) {
            throw new Error("Unknown kind: expected income, expense or savings", 400);
        }
        $ids = null;
        if ($categories !== null && trim((string)$categories) !== '') {
            $ids = array_map('intval', explode(',', (string)$categories));
        }

        [$month, $year] = Month::resolve($month, $year);
        [, $rows] = self::flowRows($month, $year);

        $transactions = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== $kind || ($ids !== null && $kind !== 'savings' && !in_array((int)$row['top'], $ids, true))) {
                continue;
            }
            unset($row['kind']);
            $row['top'] = (int)$row['top'];
            $transactions[] = $row;
        }
        return $transactions;
    }

    /**
     * Transactions of a month classified as in the money flow: income, expense or
     * savings, with their top-level category. Internal transfers (off-budget
     * categories other than the savings) are left out.
     *
     * @param int $month The month
     * @param int $year  The year
     * @return array{array<int, array<string, mixed>>, list<array<string, mixed>>} [categories by id, rows (bank_transaction columns + top + kind)]
     */
    private static function flowRows(int $month, int $year): array
    {
        [$from, $to] = Month::range($month, $year);
        $savings = self::savingsCategories();

        // Top-level category of each transaction (the category itself, or its parent)
        $sql = "SELECT t.*, IF(c.parent_category IS NULL OR c.parent_category = 0 OR c.parent_category = c.id, IFNULL(c.id, 0), c.parent_category) AS top
                FROM bank_transaction t
                LEFT JOIN bank_transaction_category c ON c.id = t.category
                WHERE t.date >= ? AND t.date < ?
                ORDER BY t.rdate DESC, t.id DESC";
        $rows = Db::execute($sql, "ss", $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC);

        $categories = [];
        foreach (Db::execute("SELECT id, name, type, icon, color FROM bank_transaction_category", "")->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
            $categories[(int)$c['id']] = $c;
        }

        $classified = [];
        foreach ($rows as $row) {
            $top = (int)$row['top'];
            if (in_array((int)$row['category'], $savings, true) || in_array($top, $savings, true)) {
                $row['kind'] = 'savings';
            } else {
                $type = $categories[$top]['type'] ?? 'HORS-BUDGET';
                // Internal transfers are neither income nor expenses; uncategorized ones are shown
                if ($type === 'HORS-BUDGET' && $top !== 0) {
                    continue;
                }
                $row['kind'] = (float)$row['amount'] > 0 ? 'income' : 'expense';
            }
            $classified[] = $row;
        }
        return [$categories, $classified];
    }

    /**
     * Ids of the savings category ("Epargne" by default, setting savings_category)
     * and of its sub-categories. Names are compared without case nor accents, in
     * PHP so that it does not depend on how the database stores the accents.
     *
     * @return list<int> The category ids (empty if there is no savings category)
     */
    private static function savingsCategories(): array
    {
        // French accents first: iconv transliteration depends on the C library
        $accents = ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'à' => 'a', 'â' => 'a', 'À' => 'A',
                    'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'Ô' => 'O', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'Ç' => 'C'];
        $normalize = fn($name) => strtolower(trim((string)preg_replace('/[^A-Za-z0-9 ]/', '', strtr((string)$name, $accents))));
        $wanted = $normalize(Config::has('savings_category') ? Config::get('savings_category') : 'Epargne');

        $categories = Db::execute("SELECT id, name, parent_category FROM bank_transaction_category", "")->get_result()->fetch_all(MYSQLI_ASSOC);
        $roots = [];
        foreach ($categories as $category) {
            if ((int)$category['id'] !== 0 && $normalize($category['name']) === $wanted) {
                $roots[] = (int)$category['id'];
            }
        }

        $ids = $roots;
        foreach ($categories as $category) {
            if (in_array((int)$category['parent_category'], $roots, true) && !in_array((int)$category['id'], $ids, true)) {
                $ids[] = (int)$category['id'];
            }
        }
        return $ids;
    }

    /**
     * Get the insights data.
     *
     * @param int|string|null $month The month to get the insights
     * @param int|string|null $year  The year to get the insights
     * @return list<array<string, mixed>> The list of insights
     */
    #[ApiRoute('/budget/insights', method: 'GET')]
    public static function getInsights(int|string|null $month = null, int|string|null $year = null): array
    {
        [$month, $year] = Month::resolve($month, $year);

        // Each query runs in a read-only transaction; a failing query gives 0
        $insights = Db::execute("SELECT * FROM budget_insight ORDER BY id", "")->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($insights as &$insight) {
            $insight["amount"] = Insight::amount($insight["sql"], $month, $year) ?? 0;
            unset($insight["sql"]);
        }

        return $insights;
    }

    /**
     * Get the year insights data.
     *
     * @param int|string|null $year The year to get the insights
     * @return array<int|string, array<string, mixed>> The list of insights by month (by insight id)
     */
    #[ApiRoute('/budget/insights/history', method: 'GET')]
    public static function getInsightsHistory(int|string|null $year = null): array
    {
        // If the date are not set we use the current year
        if (empty($year)) {
            $year = date('Y');
        }

       // insights map
       $insights = array();
         
        // for each month of the year
        for ($month = 1; $month <= 12; $month++) {
            
            // call getInsights
            $monthInsights = self::getInsights($month, $year);

            // Put the results into the insights map
            foreach ($monthInsights as $insight) {
                if (!isset($insights[$insight["id"]])) {
                    $insights[$insight["id"]] = array(
                        "id" => $insight["id"],
                        "name" => $insight["name"],
                        "history" => array()
                    );
                }
                $insights[$insight["id"]]["history"][] = array(
                    "month" => $month,
                    "amount" => $insight["amount"]
                );
            }
        }

        return $insights;
    }
}
