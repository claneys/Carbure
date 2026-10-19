<?php

use Carbure\Lib\Db;

/**
 * Rows of the queries (Db::select, Db::result, Db::rowsOn, Db::prepareOn): a result, never false.
 */
class DbTest extends DatabaseTestCase
{
    public function testSelectWithOrWithoutParameters()
    {
        $this->assertSame([['username' => 'admin']],
            Db::select("SELECT username FROM users WHERE id = ?", "i", self::ADMIN)->fetch_all(MYSQLI_ASSOC));
        $this->assertSame(1, Db::select("SELECT 1 AS one")->num_rows);
        $this->assertEquals(['one' => 1], Db::select("SELECT 1 AS one", "")->fetch_assoc());
    }

    public function testSelectOfAQueryWithoutRowsThrows()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database query returned no rows');
        Db::select("UPDATE users SET language = language WHERE id = 0");
    }

    public function testResultOfAStatementWithoutRowsThrows()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database result failed');
        Db::result(Db::execute("UPDATE users SET language = language WHERE id = ?", "i", 0));
    }

    public function testRowsAndStatementsOnAGivenConnection()
    {
        $db = Db::getConnection();
        $this->assertEquals([['n' => 2]], Db::rowsOn($db, "SELECT 2 AS n")->fetch_all(MYSQLI_ASSOC));
        $stmt = Db::prepareOn($db, "SELECT ? AS n");
        $value = 3;
        $stmt->bind_param("i", $value);
        $stmt->execute();
        $this->assertSame([['n' => 3]], Db::result($stmt)->fetch_all(MYSQLI_ASSOC));

        $this->expectException(Exception::class);
        Db::rowsOn($db, "DO 1");
    }
}
