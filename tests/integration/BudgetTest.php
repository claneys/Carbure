<?php

class BudgetTest extends DatabaseTestCase
{
    private function byName($rows)
    {
        return array_column($rows, null, 'name');
    }

    public function testTopLevelCategories()
    {
        $budget = $this->byName(Budget::get());

        // Sub-category expenses count for their parent
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertEquals(80, $budget['Alimentation']['consummed']);
        $this->assertEquals(40, $budget['Alimentation']['progress']);
        $this->assertEquals(50, $budget['Énergie']['consummed']);
        $this->assertArrayNotHasKey('Supermarché', $budget);
    }

    public function testSubCategories()
    {
        $budget = $this->byName(Budget::get(null, null, 1));

        $this->assertSame(['Supermarché'], array_keys($budget));
        $this->assertEquals(0, $budget['Supermarché']['budget']);
    }

    public function testCreateOrUpdate()
    {
        Budget::create(1, 300);
        Budget::create(4, 1000, date('n'), date('Y'));

        $budget = $this->byName(Budget::get());
        $this->assertEquals(300, $budget['Alimentation']['budget']);
        $this->assertEquals(1000, $budget['Salaire']['budget']);
    }

    public function testParentBudgetOverridesTheSumOfItsChildren()
    {
        // Alimentation has its own budget (200) and Supermarché one of 150
        Budget::create(2, 150);

        $budget = $this->byName(Budget::get());
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertSame('own', $budget['Alimentation']['budget_mode']);
        $this->assertSame(1, $budget['Alimentation']['children']);
        $this->assertEquals(150, $budget['Alimentation']['children_budget']);
        // No sub-category: always its own budget
        $this->assertSame('own', $budget['Énergie']['budget_mode']);
        $this->assertSame(0, $budget['Énergie']['children']);
    }

    public function testCopyPreviousMonth()
    {
        // Next month is empty: the budgets of the current month are copied to it
        $next = date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month'));
        $result = Budget::copy(date('n', strtotime($next)), date('Y', strtotime($next)));

        $this->assertSame(date('Y-m', strtotime($next)), $result['month']);
        $this->assertSame(date('Y-m'), $result['source']);
        $this->assertSame(2, $result['copied']);
        $this->assertFalse($result['overwrite']);

        $budget = $this->byName(Budget::get(date('n', strtotime($next)), date('Y', strtotime($next))));
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertEquals(100, $budget['Énergie']['budget']);
    }

    public function testCopyOnlyMissingByDefault()
    {
        // Next month already has its own budget for Alimentation: it is kept
        $next = date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month'));
        [$month, $year] = [(int)date('n', strtotime($next)), (int)date('Y', strtotime($next))];
        Budget::create(1, 500, $month, $year);

        $result = Budget::copy($month, $year);
        $this->assertSame(1, $result['copied']);

        $budget = $this->byName(Budget::get($month, $year));
        $this->assertEquals(500, $budget['Alimentation']['budget']);
        $this->assertEquals(100, $budget['Énergie']['budget']);
    }

    public function testCopyOverwrite()
    {
        // With overwrite, the budgets already set are replaced
        $next = date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month'));
        [$month, $year] = [(int)date('n', strtotime($next)), (int)date('Y', strtotime($next))];
        Budget::create(1, 500, $month, $year);

        $result = Budget::copy($month, $year, true);
        $this->assertSame(2, $result['copied']);
        $this->assertTrue($result['overwrite']);

        $budget = $this->byName(Budget::get($month, $year));
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertEquals(100, $budget['Énergie']['budget']);
    }

    public function testCopyEmptySource()
    {
        // The month before next month is empty: nothing is copied
        $next = date('Y-m-d', strtotime(date('Y-m-01') . ' +2 months'));
        [$month, $year] = [(int)date('n', strtotime($next)), (int)date('Y', strtotime($next))];
        $result = Budget::copy($month, $year);

        $this->assertSame(0, $result['copied']);
        $this->assertSame(date('Y-m', strtotime(date('Y-m-01') . ' +1 month')), $result['source']);
    }

    public function testCopyAcrossYearBoundary()
    {
        // January 2020 is copied from December 2019
        Budget::create(1, 77, 12, 2019);

        $result = Budget::copy(1, 2020);
        $this->assertSame('2020-01', $result['month']);
        $this->assertSame('2019-12', $result['source']);
        $this->assertSame(1, $result['copied']);

        $budget = $this->byName(Budget::get(1, 2020));
        $this->assertEquals(77, $budget['Alimentation']['budget']);
    }

    public function testCopyKeepsTheSumOfChildren()
    {
        // A 0 budget means "sum of the sub-categories": copied as is
        Budget::create(1, 0, 2, 2020);
        Budget::create(2, 160, 2, 2020);

        $result = Budget::copy(3, 2020);
        $this->assertSame('2020-02', $result['source']);
        $this->assertSame(2, $result['copied']);

        $budget = $this->byName(Budget::get(3, 2020));
        $this->assertSame('children', $budget['Alimentation']['budget_mode']);
        $this->assertEquals(160, $budget['Alimentation']['budget']);
    }

    public function testParentBudgetIsTheSumOfItsChildren()
    {
        // 0 removes the override of Alimentation: the budget of Supermarché is used
        Budget::create(1, 0);
        Budget::create(2, 160);

        $budget = $this->byName(Budget::get());
        $this->assertEquals(160, $budget['Alimentation']['budget']);
        $this->assertSame('children', $budget['Alimentation']['budget_mode']);
        $this->assertEquals(50, $budget['Alimentation']['progress']);

        // Planned budget of the month: Alimentation 160 (sum) + Énergie 100
        $this->assertEquals(260, Budget::getTrends(1)[0]['planned']);
    }

    public function testTrends()
    {
        $trends = Budget::getTrends();

        $this->assertCount(24, $trends);
        $this->assertSame(date('Y-m'), $trends[23]['month']);

        // Current month of the data set: -80 -50 expenses, +2000 income,
        // -10 in category 0 which is off-budget
        $current = $trends[23];
        $this->assertEquals(130, $current['debit']);
        $this->assertEquals(2000, $current['credit']);
        $this->assertEquals(-10, $current['offBudget']);
        // No savings category in the data set
        $this->assertEquals(0, $current['savings']);
        // Only the top-level budgets: Alimentation 200 + Énergie 100
        $this->assertEquals(300, $current['planned']);

        // Empty months are present with zeros
        $this->assertEquals(0, $trends[0]['debit']);
    }

    public function testTrendsSavingsFromTheSavingsCategory()
    {
        // "Épargne" (accent and case insensitive) with a sub-category
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (20, 'Épargne', 0, 'HORS-BUDGET', 'banknote', 'green')");
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (21, 'Livret A', 20, 'HORS-BUDGET', 'banknote', 'green')");
        $date = date('Y-m-05');
        foreach ([['s1', 20, -300], ['s2', 21, -200], ['s3', 21, 50]] as [$uuid, $category, $amount]) {
            Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user)
                VALUES (?, ?, ?, 1, 'VIR EPARGNE', ?, ?, 1)", "sssid", $uuid, $date, $date, $category, $amount);
        }

        // 300 + 200 put aside, 50 taken back
        $this->assertEquals(450, Budget::getTrends()[23]['savings']);
    }

    public function testFlow()
    {
        // Current month of the data set: +2000 income (Salaire), -80 Supermarché (child of
        // Alimentation), -50 Énergie, -10 uncategorized
        $flow = Budget::flow();
        $this->assertSame(date('Y-m'), $flow['month']);
        $this->assertEquals(2000, $flow['totalIncome']);
        $this->assertSame('Salaire', $flow['income'][0]['name']);
        $expenses = array_column($flow['expenses'], 'amount', 'name');
        // The child is grouped with its parent
        $this->assertEquals(80, $expenses['Alimentation']);
        $this->assertEquals(50, $expenses['Énergie']);
        $this->assertEquals(140, $flow['totalExpenses']);
        $this->assertEquals(0, $flow['savings']);
        $this->assertEquals(1860, $flow['balance']);

        // A month without transactions
        $empty = Budget::flow(1, 2001);
        $this->assertSame([], $empty['income']);
        $this->assertEquals(0, $empty['balance']);
    }

    public function testFlowTransactions()
    {
        // The transactions behind each node add up to its amount
        $flow = Budget::flow();
        foreach ($flow['expenses'] as $node) {
            $rows = Budget::flowTransactions('expense', (string)$node['id']);
            $this->assertEquals($node['amount'], -array_sum(array_column($rows, 'amount')), $node['name'] ?? 'uncategorized');
            $this->assertSame([$node['id']], array_values(array_unique(array_column($rows, 'top'))));
        }
        $income = Budget::flowTransactions('income');
        $this->assertEquals($flow['totalIncome'], array_sum(array_column($income, 'amount')));

        // Several categories at once ("Others" of the diagram)
        $ids = implode(',', array_column($flow['expenses'], 'id'));
        $this->assertEquals($flow['totalExpenses'], -array_sum(array_column(Budget::flowTransactions('expense', $ids), 'amount')));

        $this->assertSame([], Budget::flowTransactions('expense', null, 1, 2001));

        $this->expectException(Error::class);
        Budget::flowTransactions('balance');
    }

    public function testTrendsWithOffset()
    {
        // The 3 months before the last 3 months
        $trends = Budget::getTrends(3, 3);

        $this->assertCount(3, $trends);
        $this->assertSame(date('Y-m', strtotime(date('Y-m-01') . ' -3 months')), $trends[2]['month']);
        $this->assertEquals(0, $trends[2]['debit']);
    }

    public function testTrendsOffBudgetAndBounds()
    {
        Db::query("UPDATE bank_transaction_category SET type='HORS-BUDGET' WHERE id=3");

        $trends = Budget::getTrends(1);
        $this->assertCount(1, $trends);
        $this->assertEquals(80, $trends[0]['debit']);
        $this->assertEquals(-60, $trends[0]['offBudget']);

        $this->assertCount(60, Budget::getTrends(500));
    }

    public function testInsights()
    {
        $insights = Budget::getInsights();

        $this->assertSame('Dépenses', $insights[0]['name']);
        $this->assertEquals(-140, $insights[0]['amount']);
        $this->assertArrayNotHasKey('sql', $insights[0]);
    }

    public function testInsightsCastTheDate()
    {
        // Used to be injected as is in the SQL: now cast to 1, i.e. January 2020 only
        $this->assertEquals(-5, Budget::getInsights('1) OR (1=1', 2020)[0]['amount']);
    }

    public function testInsightsHistory()
    {
        $history = Budget::getInsightsHistory();

        $this->assertCount(12, $history[1]['history']);
        $this->assertEquals(-140, $history[1]['history'][date('n') - 1]['amount']);
    }
}
