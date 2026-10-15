<?php

class CategoryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Categories and rules are managed by administrators
        $this->loginAs(self::ADMIN);
    }

    public function testUpdateAndCountRule()
    {
        $rule = Category::createKeyword('carrefour', 2, true);
        $this->assertSame(1, $rule['notify']);
        $this->assertEquals(1, array_column(Category::getKeywords(), 'notify', 'keyword')['CARREFOUR']);

        $updated = Category::updateKeyword($rule['id'], 'cb super', 2, false);
        $this->assertSame('CB SUPER', $updated['keyword']);
        $this->assertSame(0, $updated['notify']);

        // The data set has transactions labelled "CB SUPERMARCHE", in category 2
        $count = Category::countKeyword($rule['id']);
        $expected = (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label LIKE '%CB SUPER%'", "")['n'];
        $this->assertSame($expected, $count['matching']);
        $this->assertGreaterThanOrEqual(1, $count['categorized']);
        $this->assertLessThanOrEqual($count['matching'], $count['categorized']);

        try {
            Category::updateKeyword($rule['id'], 'EDF', 2);
            $this->fail('Duplicate keyword accepted');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Category::countKeyword(9999);
    }

    public function testUserCannotManageCategoriesAndRules()
    {
        $this->loginAs(self::USER);
        $this->assertCount(5, Category::get());
        $this->assertAdminOnly(['POST /category', 'PUT /category', 'DELETE /category', 'POST /category/keyword',
            'DELETE /category/keyword', 'POST /category/keyword/apply', 'PUT /category/keyword', 'GET /category/keyword/count']);

        // Refused before the method is called: the category is still there
        try {
            $this->callRoute('DELETE', '/category', ['id' => 1]);
            $this->fail('Deleted by a user');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertCount(5, Category::get());
    }

    public function testGet()
    {
        $this->assertCount(5, Category::get());
    }

    public function testCreateUpdateDelete()
    {
        $category = Category::create('Vacances', 'DEBIT', 0, 'airplane', 'teal');
        $this->assertGreaterThan(4, $category['id']);

        $child = Category::create('Hôtels', 'DEBIT', $category['id'], 'house', '');
        $this->assertSame($category['id'], $child['parent_category']);

        $updated = Category::update($child['id'], 'Hébergement', 'DEBIT', $category['id'], 'house', '#ff0000');
        $this->assertSame('Hébergement', $updated['name']);

        // A category with sub-categories cannot be deleted
        try {
            Category::delete($category['id']);
            $this->fail('Deleted with a sub-category');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->assertTrue(Category::delete($child['id']));
        $this->assertTrue(Category::delete($category['id']));
        $this->assertCount(5, Category::get());
    }

    public function testDeleteMovesTransactionsAndRemovesBudgetsAndRules()
    {
        // Énergie (3): transaction u2, a budget and the EDF rule
        $this->assertTrue(Category::delete(3));

        $this->assertEquals(0, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid = 'u2'", "")['category']);
        $this->assertEquals(0, Db::queryOne("SELECT COUNT(*) AS n FROM budget WHERE category = 3", "")['n']);
        $this->assertNull(Category::find('PRLV SEPA EDF '));
    }

    public function testValidation()
    {
        foreach ([
            fn() => Category::create('', 'DEBIT'),
            fn() => Category::create('X', 'OTHER'),
            fn() => Category::create('X', 'DEBIT', 0, 'bad icon!'),
            fn() => Category::create('X', 'DEBIT', 2),              // 2 is already a sub-category
            fn() => Category::update(1, 'Alimentation', 'DEBIT', 4), // 1 has sub-categories
            fn() => Category::update(0, 'X', 'DEBIT'),               // protected
        ] as $i => $call) {
            try {
                $call();
                $this->fail("Case $i accepted");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), "Case $i: " . $e->getMessage());
            }
        }

        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Category::create('Salaire', 'CREDIT');
    }

    public function testKeywords()
    {
        $rules = Category::getKeywords();

        $this->assertCount(3, $rules);
        $this->assertEqualsCanonicalizing(['Énergie', 'Salaire', 'Supermarché'], array_column($rules, 'category_name'));
    }

    public function testCreateAndDeleteKeyword()
    {
        $rule = Category::createKeyword('  bio ', 1);
        $this->assertSame('BIO', $rule['keyword']);

        // Applied at once to the history: "CB 100% BIO" (category 0) is categorized
        $this->assertSame(1, $rule['applied']);
        $this->assertEquals(1, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u4'", "")['category']);
        $this->assertSame(['updated' => 0], Category::applyKeywords());

        $this->assertTrue(Category::deleteKeyword($rule['id']));
        $this->assertCount(3, Category::getKeywords());
    }

    public function testRulesApplyToCategorizedTransactions()
    {
        // "CB SUPERMARCHE" (u1) is in Supermarché (2); a rule moves it, categorized or not
        $rule = Category::createKeyword('CB SUPER', 3);
        $this->assertGreaterThanOrEqual(1, $rule['applied']);
        $this->assertEquals(3, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u1'", "")['category']);

        // Modified rule: applied again
        $updated = Category::updateKeyword($rule['id'], 'CB SUPER', 1);
        $this->assertGreaterThanOrEqual(1, $updated['applied']);
        $this->assertEquals(1, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u1'", "")['category']);

        // Resynchronize: every rule on every transaction (the first matching rule wins)
        Db::query("UPDATE bank_transaction SET category = 4 WHERE uuid = 'u2'");
        $this->assertGreaterThanOrEqual(1, Category::applyKeywords()['updated']);
        $this->assertEquals(3, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u2'", "")['category']);
    }

    public function testCreateDuplicateKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Category::createKeyword('edf', 1);
    }

    public function testCreateKeywordUnknownCategory()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Category::createKeyword('NEW', 99);
    }

    public function testCreateEmptyKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Category::createKeyword('  ', 1);
    }

    public function testDeleteUnknownKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Category::deleteKeyword(99);
    }

    public function testFind()
    {
        $this->assertEquals(3, Category::find('PRLV SEPA EDF '));
        $this->assertNull(Category::find('UNKNOWN'));
    }
}
