<?php

use Carbure\Lib\Db;
use Carbure\Resources\Insight;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../unit/InsightCookbookDocTest.php';

/**
 * Recipes of the insight cookbook (docs/insights.md) run on a household's data of
 * March 2025 (and the months before), with the value each one must give.
 */
class InsightCookbookTest extends DatabaseTestCase
{
    /** Month the recipes are run for: March 2025 (the 1st is a Saturday) */
    private const MONTH = 3;
    private const YEAR = 2025;

    /**
     * Value of each recipe for March 2025, by name (see the data of setUp).
     */
    private const EXPECTED = [
        // Alimentation: Courses -60 -90, Restaurants -40 -25
        'Alimentation' => -215.0,
        'Panier moyen courses' => -75.0,
        'Amazon' => -35.0,
        'Livraison repas' => -25.0,
        // EDF and Netflix, also debited in February
        'Prélèv. récurrents' => -85.0,
        // The 1st, 2nd and 22nd (Saturday, Sunday, Saturday): -60 -40 -35 out of -490
        'Dépenses week-end' => -135.0,
        'Part week-end %' => 27.55,
        // Cards -300 out of -490
        'Part carte %' => 61.22,
        'Nb retraits' => 1.0,
        'Plus gros poste' => -150.0,
        'Compte joint' => -150.0,
        // February -200: +145 %
        'Variation vs M-1 %' => 145.0,
        // March 2024 -313
        'Écart vs N-1' => -177.0,
        // December to February: -500 / 3
        'Écart vs moy. 3 mois' => -323.33,
        'Cumul depuis janvier' => -890.0,
        // April 2024 to March 2025: -990 / 12
        'Moyenne 12 mois' => -82.5,
        // (2530 - 490) / 2530
        'Taux d\'épargne %' => 80.63,
        'Épargne versée' => 300.0,
        // September 2024 to February 2025
        'Matelas 6 mois' => 500.0,
        // 300 saved before March / (500 / 6)
        'Matelas en mois' => 3.6,
        // Past month: its spending
        'Dépenses projetées' => -490.0,
        // Alimentation 200 - 215, Abonnements 20 - 15, Transport 40 - 50
        'Budget restant' => -20.0,
        'Catégories dépassées' => 2.0,
        // 280 / 260
        'Budget consommé %' => 107.69,
        'Nb non catégorisées' => 2.0,
        'Nb non vérifiées' => 8.0,
        'Frais de l\'année' => -5.0,
        'Remboursements' => 30.0,
        // The two CB TOTAL ACCESS of the 6th and 7th
        'Doublons possibles' => 1.0,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES
            (5, 'Courses', 1, 'DEBIT', 'shopping_cart', 'green'),
            (6, 'Restaurants', 1, 'DEBIT', 'local_dining', 'green'),
            (7, 'Transport', 0, 'DEBIT', 'directions_car', 'red'),
            (8, 'Carburant', 7, 'DEBIT', 'local_gas_station', 'red'),
            (9, 'Abonnements', 0, 'DEBIT', 'subscriptions', 'cyan'),
            (10, 'Frais bancaires', 0, 'DEBIT', 'account_balance_wallet', 'darkGray'),
            (11, 'Revenus', 0, 'CREDIT', 'payments', 'green'),
            (12, 'Remboursements', 11, 'CREDIT', 'currency_exchange', 'green'),
            (13, 'Épargne', 0, 'HORS-BUDGET', 'savings', 'purple'),
            (14, 'Livrets', 13, 'HORS-BUDGET', 'account_balance', 'purple'),
            (15, 'Virements internes', 0, 'HORS-BUDGET', 'sync_alt', 'gray')");

        Db::query("INSERT INTO bank_account (bank_name, account_number, label, user_id) VALUES ('bnp', '222', 'Compte joint', 1)");

        // [date, type, label, category, amount, pointed, account]
        $rows = [
            ['2025-03-01', 7, 'CB CARREFOUR', 5, -60, 1, '222'],
            ['2025-03-02', 7, 'CB RESTAURANT LE ZINC', 6, -40, 1, null],
            ['2025-03-04', 7, 'CB LECLERC', 5, -90, 1, '222'],
            ['2025-03-05', 2, 'PRLV SEPA EDF', 3, -70, 1, null],
            ['2025-03-06', 2, 'PRLV NETFLIX', 9, -15, 1, null],
            ['2025-03-06', 7, 'CB TOTAL ACCESS', 8, -25, 1, null],
            ['2025-03-07', 7, 'CB TOTAL ACCESS', 8, -25, 1, null],
            ['2025-03-10', 7, 'CB UBER EATS PARIS', 6, -25, 0, null],
            ['2025-03-12', 6, 'RETRAIT DAB', 0, -100, 0, null],
            ['2025-03-14', 9, 'COTISATION CARTE', 10, -5, 0, null],
            ['2025-03-20', 1, 'VIR SALAIRE', 4, 2500, 0, null],
            ['2025-03-21', 1, 'VIR LIVRET A', 14, -300, 0, null],
            ['2025-03-22', 7, 'CB AMAZON MARKETPLACE', 0, -35, 0, null],
            ['2025-03-25', 5, 'REMBOURSEMENT CPAM', 12, 30, 0, null],
            ['2025-03-28', 1, 'VIR INTERNE', 15, -200, 0, null],
            ['2025-02-05', 2, 'PRLV SEPA EDF', 3, -70, 1, null],
            ['2025-02-06', 2, 'PRLV NETFLIX', 9, -15, 1, null],
            ['2025-02-10', 7, 'CB CARREFOUR', 5, -115, 1, null],
            ['2025-02-20', 1, 'VIR SALAIRE', 4, 2500, 1, null],
            ['2025-02-21', 1, 'VIR LIVRET A', 14, -300, 1, null],
            ['2025-01-10', 7, 'CB CARREFOUR', 5, -200, 1, null],
            ['2025-01-20', 1, 'VIR SALAIRE', 4, 2500, 1, null],
            ['2024-12-10', 7, 'CB CARREFOUR', 5, -100, 1, null],
            ['2024-03-10', 7, 'CB CARREFOUR', 5, -300, 1, null],
            ['2024-03-15', 2, 'PRLV NETFLIX', 9, -13, 1, null],
        ];
        foreach ($rows as $i => [$date, $type, $label, $category, $amount, $pointed, $account]) {
            Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, pointed, account_number, user)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)", "sssisidis", "cookbook$i", $date, $date, $type, $label, $category, $amount, $pointed, $account);
        }

        Db::query("INSERT INTO budget (category, amount, date) VALUES
            (1, 200, '2025-03-01'), (9, 20, '2025-03-01'), (7, 40, '2025-03-01')");
    }

    /**
     * @return array<string, array{0: array{title: string, sql: string, name: string, color: string, icon: string}}>
     */
    public static function recipeProvider()
    {
        return InsightCookbookDocTest::recipeProvider();
    }

    #[DataProvider('recipeProvider')]
    public function testRecipeGivesItsValue($recipe)
    {
        $result = Insight::check($recipe['sql'], self::MONTH, self::YEAR);
        $this->assertTrue($result['valid'], $result['error'] ?? '');
        $this->assertArrayHasKey($recipe['name'], self::EXPECTED, 'Expected value of the recipe');
        $this->assertSame(self::EXPECTED[$recipe['name']], $result['amount']);
    }

    #[DataProvider('recipeProvider')]
    public function testRecipeRunsOnAMonthWithoutData($recipe)
    {
        // Nothing in 2010: no error, a number or nothing
        $result = Insight::check($recipe['sql'], 6, 2010);
        $this->assertTrue($result['valid'], $result['error'] ?? '');
        $this->assertContains(get_debug_type($result['amount']), ['null', 'float']);
    }

    public function testRecipeOfTheCurrentMonthProjectsTheSpending()
    {
        $recipe = array_column(InsightCookbookDocTest::recipes(), 'sql', 'name')['Dépenses projetées'];
        // The spending of the current month (DatabaseTestCase: -80 -50 -10) at the pace of the days gone by
        $expected = round(-140 * (int)date('t') / (int)date('j'), 2);
        $this->assertSame($expected, Insight::check($recipe)['amount']);
    }
}
