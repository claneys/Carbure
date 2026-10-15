<?php

class InsightTest extends DatabaseTestCase
{
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
}
