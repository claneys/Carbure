<?php

use Carbure\Lib\SqlTables;
use Carbure\Resources\Insight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tables an insight query can read (Insight::READABLE_TABLES) and the reading of the
 * tables of a query (SqlTables). No database: the checks run before the query.
 */
class InsightQueryTest extends TestCase
{
    /**
     * Every table of sql/carbure.sql is either readable or refused, not both: a new table
     * must be classified.
     */
    public function testEveryTableOfTheSchemaIsClassified()
    {
        preg_match_all('/^CREATE TABLE `([a-z_]+)`/m', file_get_contents(__DIR__ . '/../../sql/carbure.sql'), $matches);
        $schema = $matches[1];
        sort($schema);

        $refused = array_diff(Insight::REFUSED_TABLES, ['mysql', 'information_schema', 'performance_schema', 'sys']);
        $this->assertSame([], array_intersect(Insight::READABLE_TABLES, Insight::REFUSED_TABLES));
        $classified = [...Insight::READABLE_TABLES, ...$refused];
        sort($classified);
        $this->assertSame($schema, $classified, 'Add the new table to Insight::READABLE_TABLES or Insight::REFUSED_TABLES');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedQueries()
    {
        return [
            'MFA secret' => ['SELECT ORD(SUBSTRING(secret,1,1)) AS amount FROM user_mfa'],
            'error message' => ['SELECT EXTRACTVALUE(1,CONCAT(0x7e,(SELECT secret FROM user_mfa LIMIT 1))) AS amount'],
            'backticks' => ['SELECT COUNT(*) AS amount FROM `user_mfa`'],
            'qualified' => ['SELECT COUNT(*) AS amount FROM `carbure`.`user_mfa`'],
            'qualified without backticks' => ['SELECT COUNT(*) AS amount FROM carbure.oauth_clients'],
            'upper case' => ['SELECT COUNT(*) AS amount FROM MFA_RECOVERY_CODE'],
            'sub-query' => ['SELECT (SELECT COUNT(*) FROM mfa_trusted_browser) AS amount FROM bank_transaction'],
            'union' => ['SELECT SUM(amount) AS amount FROM bank_transaction UNION SELECT id FROM settings'],
            'join' => ['SELECT SUM(t.amount) AS amount FROM bank_transaction t JOIN oauth_codes c ON 1=1'],
            'comma join' => ['SELECT SUM(t.amount) AS amount FROM bank_transaction t, schema_migrations m'],
            'information_schema' => ['SELECT COUNT(*) AS amount FROM information_schema.tables'],
            'other database' => ['SELECT COUNT(*) AS amount FROM otherdb.accounts'],
            'other database with backticks' => ['SELECT COUNT(*) AS amount FROM `otherdb`.`accounts`'],
            'unknown table' => ['SELECT COUNT(*) AS amount FROM secrets'],
            'unknown table after a comma' => ['SELECT COUNT(*) AS amount FROM bank_transaction a JOIN budget b ON a.category = b.category, secrets'],
            'parentheses around tables' => ['SELECT COUNT(*) AS amount FROM (bank_transaction, otherdb.secrets)'],
            'derived table' => ['SELECT SUM(x) AS amount FROM (SELECT 1 AS x FROM secrets) s'],
            'IN sub-query' => ['SELECT COUNT(*) AS amount FROM bank_transaction WHERE category IN (SELECT id FROM `secrets`)'],
            'quoted table name' => ['SELECT COUNT(*) AS amount FROM "secrets"'],
            'MySQL TABLE statement' => ['SELECT 1 AS amount UNION TABLE secrets'],
            'backslash' => ["SELECT COUNT(*) AS amount FROM bank_transaction WHERE label = '\\' FROM x '"],
            'insights' => ['SELECT COUNT(*) AS amount FROM budget_insight'],
            'users' => ['SELECT COUNT(*) AS amount FROM users'],
        ];
    }

    #[DataProvider('refusedQueries')]
    public function testRefusedQuery($sql)
    {
        try {
            Insight::validate('X', 'red', $sql);
            $this->fail("Accepted: $sql");
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertMatchesRegularExpression('/cannot read|backslash/', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedQueries()
    {
        return [
            'transactions' => ['SELECT SUM(amount) AS amount FROM bank_transaction WHERE MONTH(date)={month} AND YEAR(date)={year}'],
            'join with backticks' => ['SELECT SUM(t.amount) AS amount FROM `bank_transaction` t JOIN `bank_transaction_category` c ON c.id = t.category'],
            'budget and categories' => ['SELECT SUM(b.amount) AS amount FROM budget b, bank_transaction_category c WHERE c.id = b.category'],
            'accounts' => ['SELECT COUNT(*) AS amount FROM bank_account a LEFT JOIN bank_backend k ON k.name = a.bank_name'],
            'rules' => ['SELECT COUNT(*) AS amount FROM bank_transaction_category_keyword'],
            'derived table' => ['SELECT AVG(s) AS amount FROM (SELECT SUM(amount) AS s FROM bank_transaction GROUP BY category) x'],
            'EXTRACT and TRIM' => ["SELECT COUNT(*) AS amount FROM bank_transaction WHERE EXTRACT(MONTH FROM date) = {month} AND TRIM(LEADING ' ' FROM label) <> ''"],
            'IN sub-query' => ['SELECT COUNT(*) AS amount FROM bank_transaction WHERE category IN (SELECT id FROM bank_transaction_category)'],
            'no table' => ['SELECT 42 AS amount'],
            'dual' => ['SELECT 42 AS amount FROM DUAL'],
            'table name in a text' => ["SELECT COUNT(*) AS amount FROM bank_transaction WHERE label LIKE '%secrets FROM x%'"],
        ];
    }

    #[DataProvider('acceptedQueries')]
    public function testAcceptedQuery($sql)
    {
        $this->assertSame($sql, Insight::validate('X', 'red', $sql)[2]);
    }

    public function testTableReferences()
    {
        $this->assertSame(['bank_transaction', 'budget', 'bank_account'], SqlTables::references(
            'SELECT 1 AS amount FROM `bank_transaction` t, budget JOIN (SELECT x FROM bank_account) a ON 1 WHERE (1, 2) IN (SELECT 1, 2)'
        ));
        $this->assertSame(['`db`.`t`', 'db.t', "'x'"], SqlTables::references("SELECT 1 FROM `db`.`t` JOIN db.t STRAIGHT_JOIN 'x'"));
        $this->assertSame(['a`b'], SqlTables::references('SELECT 1 FROM `a``b`'));
        $this->assertSame([], SqlTables::references('SELECT EXTRACT(YEAR FROM date), SUBSTRING(label FROM 2) AS amount'));
        $this->assertSame([], SqlTables::references('SELECT 1)) FROM'));
    }
}
