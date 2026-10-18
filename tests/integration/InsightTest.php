<?php

use Carbure\Lib\Db;
use Carbure\Resources\Budget;
use Carbure\Resources\Insight;

class InsightTest extends DatabaseTestCase
{
    private const SQL_SLOW_MONTH = "SELECT SUM(amount) AS amount FROM bank_transaction WHERE label = 'Slow' AND MONTH(date)={month} AND YEAR(date)={year}";

    private const SQL = 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE amount > 0 AND MONTH(date)={month} AND YEAR(date)={year}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs(self::ADMIN);
    }

    public function testCrud()
    {
        $this->assertCount(1, Insight::get());

        $created = Insight::create('Revenus', 'green', self::SQL . ';');
        $this->assertSame(self::SQL, $created['sql']);
        $this->assertEquals(2000, $created['amount']);
        $this->assertCount(2, Budget::getInsights());

        $updated = Insight::update($created['id'], 'Entrées', 'blue', self::SQL);
        $this->assertSame('Entrées', $updated['name']);

        $this->assertTrue(Insight::delete($created['id']));
        $this->assertCount(1, Insight::get());
    }

    public function testIconAndCheck()
    {
        $created = Insight::create('Revenus', 'green', self::SQL, 'savings');
        $this->assertSame('savings', $created['icon']);
        $this->assertSame('savings', array_column(Insight::get(), 'icon', 'name')['Revenus']);
        $this->assertSame('savings', array_column(Budget::getInsights(), 'icon', 'name')['Revenus']);
        $this->assertNull(Insight::update($created['id'], 'Revenus', 'green', self::SQL, '')['icon']);

        $this->assertSame(['valid' => true, 'amount' => 2000.0], Insight::check(self::SQL));
        $this->assertSame(['valid' => true, 'amount' => null], Insight::check(self::SQL, 1, 2001));
        $invalid = Insight::check('SELECT SUM(amount) AS amount FROM bank_transaction WHERE');
        $this->assertFalse($invalid['valid']);
        $this->assertStringContainsString('SQL syntax', $invalid['error']);
        $this->assertFalse(Insight::check('SELECT 1 AS amount FROM users')['valid']);

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Insight::create('X', 'red', self::SQL, 'Bad Icon');
    }

    public function testUserCannotManageInsights()
    {
        $this->loginAs(self::USER);
        $this->assertCount(1, Budget::getInsights());
        $this->assertAdminOnly(['GET /insight', 'POST /insight', 'POST /insight/check', 'PUT /insight', 'DELETE /insight']);
    }

    public function testDangerousQueriesAreRefused()
    {
        foreach ([
            'DELETE FROM bank_transaction',
            'SELECT 1 AS amount; DROP TABLE users',
            'SELECT password AS amount FROM users',
            'SELECT COUNT(*) AS amount FROM api_tokens',
            'SELECT SLEEP(10) AS amount',
            'SELECT 1 AS amount INTO OUTFILE \'/tmp/x\'',
            'SELECT 1 AS amount -- comment',
            'SELECT 1 AS amount /* x */',
            'SELECT LOAD_FILE(\'/etc/passwd\') AS amount',
            'SELECT COUNT(*) AS amount FROM information_schema.tables',
            'SELECT SUM(amount) FROM bank_transaction',          // no amount column
            'SELECT SUM(nope) AS amount FROM bank_transaction', // fails on test
            'WITH x AS (SELECT 1) SELECT 1 AS amount',
        ] as $sql) {
            try {
                Insight::create('X', 'red', $sql);
                $this->fail("Accepted: $sql");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $sql . ': ' . $e->getMessage());
            }
        }
        $this->assertCount(1, Insight::get());
    }

    public function testInvalidFields()
    {
        foreach ([['', 'red'], [str_repeat('x', 21), 'red'], ['X', 'fuchsia']] as [$name, $color]) {
            try {
                Insight::create($name, $color, self::SQL);
                $this->fail("Accepted $name/$color");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Insight::update(999, 'X', 'red', self::SQL);
    }

    public function testFailingStoredQueryGivesZero()
    {
        // A query stored before the checks (or broken by a schema change)
        Db::query("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Cassé', 'gray', 'SELECT nope AS amount FROM nothing')");
        $insights = array_column(Budget::getInsights(), 'amount', 'name');
        $this->assertEquals(0, $insights['Cassé']);
        $this->assertNotEquals(0, $insights['Dépenses']);
    }

    public function testReadOnlyTransaction()
    {
        // Even a stored write is not executed
        Db::query("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Écriture', 'gray', 'UPDATE bank_transaction SET amount = 0')");
        Budget::getInsights();
        $this->assertNotEquals(0, Db::queryOne("SELECT SUM(ABS(amount)) AS s FROM bank_transaction", "")['s']);
    }

    public function testStoredQueryOnARefusedTableIsNotRun()
    {
        // A query stored before the allow-list (or imported in the database)
        Db::query("INSERT INTO user_mfa (user_id, secret, created_at) VALUES (1, 'JBSWY3DPEHPK3PXP', NOW())");
        Db::query("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Secret', 'gray', 'SELECT ORD(SUBSTRING(secret,1,1)) AS amount FROM user_mfa')");
        $this->assertEquals(0, array_column(Budget::getInsights(), 'amount', 'name')['Secret']);

        $check = Insight::check('SELECT EXTRACTVALUE(1,CONCAT(0x7e,(SELECT secret FROM user_mfa LIMIT 1))) AS amount');
        $this->assertFalse($check['valid']);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $check['error']);
        $this->assertStringContainsString('cannot read user_mfa', $check['error']);
    }

    public function testSlowQueryIsStopped()
    {
        // 300 transactions: the cartesian product of four copies has 8 billion rows (SUM: MySQL
        // computes a COUNT(*) of a cartesian product without reading it)
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = [md5("slow$i"), '2020-01-01', '2020-01-01', 'Slow', -1];
        }
        Db::executeMany("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user) VALUES (?, ?, ?, 1, ?, 0, ?, 1)", "ssssd", $rows);
        $slow = 'SELECT SUM(a.amount) AS amount FROM bank_transaction a, bank_transaction b, bank_transaction c, bank_transaction d';

        $previous = Insight::$maxSeconds;
        Insight::$maxSeconds = 0.05;
        try {
            $start = microtime(true);
            $check = Insight::check($slow);
            $this->assertFalse($check['valid']);
            $this->assertSame('The query takes more than 0.05 seconds', $check['error']);
            $this->assertLessThan(5, microtime(true) - $start);

            // In the list, a value of 0 without breaking the other insights
            Db::execute("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Lent', 'gray', ?)", "s", $slow);
            $insights = array_column(Budget::getInsights(1, 2020), 'amount', 'name');
            $this->assertEquals(0, $insights['Lent']);
            $this->assertLessThanOrEqual(-300, $insights['Dépenses']);

            try {
                Insight::create('Lent', 'red', $slow);
                $this->fail('Slow query saved');
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
                $this->assertStringContainsString('more than 0.05 seconds', $e->getMessage());
            }
        } finally {
            Insight::$maxSeconds = $previous;
        }
        // The connection stays usable, with the default time limit
        $this->assertSame(['valid' => true, 'amount' => -300.0], Insight::check(self::SQL_SLOW_MONTH, 1, 2020));
    }
}
