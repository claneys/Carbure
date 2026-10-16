<?php

use Carbure\Resources\Insight;
use PHPUnit\Framework\TestCase;

/**
 * Starter data offered by the installation wizard (sql/starter.json).
 */
class StarterTest extends TestCase
{
    /** Color names of the categories (CATEGORY_COLORS in portal/utils/categoryIcons.js) */
    private const CATEGORY_COLORS = ['red', 'orange', 'yellow', 'green', 'mint', 'teal', 'cyan', 'blue',
        'indigo', 'purple', 'pink', 'magenta', 'brown', 'gray', 'darkGray'];

    private static function starter()
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/sql/starter.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testCategories()
    {
        $ids = [0];
        $colors = [];
        foreach (self::starter()['categories'] as $category) {
            $this->assertNotContains($category['id'], $ids, "Duplicate id {$category['id']}");
            // The parent is created first (foreign key)
            $this->assertContains($category['parent'], $ids, "Unknown parent of {$category['fr']}");
            $this->assertContains($category['type'], ['DEBIT', 'CREDIT', 'HORS-BUDGET']);
            $this->assertContains($category['color'], self::CATEGORY_COLORS, $category['fr']);
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $category['icon']);
            foreach (['fr', 'en'] as $language) {
                $this->assertNotSame('', $category[$language]);
                $this->assertLessThanOrEqual(50, mb_strlen($category[$language]));
            }
            // A sub-category has the color of its parent
            if ($category['parent'] !== 0) {
                $this->assertSame($colors[$category['parent']], $category['color'], "Color of {$category['fr']}");
            }
            $colors[$category['id']] = $category['color'];
            $ids[] = $category['id'];
        }
        // The id is a tinyint
        $this->assertLessThan(256, max($ids));
    }

    public function testRules()
    {
        $starter = self::starter();
        $ids = array_column($starter['categories'], 'id');
        $keywords = [];
        foreach ($starter['rules'] as [$keyword, $category]) {
            $this->assertContains($category, $ids, "Unknown category of $keyword");
            $this->assertLessThanOrEqual(60, mb_strlen($keyword));
            $this->assertSame(strtoupper($keyword), $keyword, "The labels are in upper case: $keyword");
            // The first rule found in a label wins: an earlier rule contained in this one
            // would always win over it
            foreach ($keywords as $earlier) {
                $this->assertStringNotContainsString($earlier, $keyword, "$keyword is hidden by $earlier");
            }
            $keywords[] = $keyword;
        }
    }

    public function testInsights()
    {
        foreach (self::starter()['insights'] as $insight) {
            foreach (['fr', 'en'] as $language) {
                [$name, $color, $sql] = Insight::validate($insight[$language], $insight['color'], $insight['sql']);
                $this->assertSame($insight[$language], $name);
                $this->assertSame($insight['sql'], $sql);
            }
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $insight['icon']);
        }
    }
}
