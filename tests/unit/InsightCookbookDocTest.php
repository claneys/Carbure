<?php

use Carbure\Resources\Insight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recipes of the insight cookbook (docs/insights.md): each ```sql block comes with its
 * name, color and icon, and passes the checks of an insight. The integration test
 * InsightCookbookTest runs them on a database.
 */
class InsightCookbookDocTest extends TestCase
{
    const DOC = __DIR__ . '/../../docs/insights.md';

    /**
     * Recipes of the page, in order: one per section with a ```sql block.
     *
     * @return list<array{title: string, sql: string, name: string, color: string, icon: string}>
     */
    public static function recipes()
    {
        $recipes = [];
        foreach (preg_split('/^### /m', file_get_contents(self::DOC)) as $section) {
            if (!str_contains($section, '```sql')) {
                continue;
            }
            preg_match_all('/```sql\n(.*?)\n```/s', $section, $sql);
            preg_match_all('/^- \*\*Nom\*\* : `([^`]+)` — couleur `([a-z]+)`, icône `([a-z_]+)`$/m', $section, $meta);
            $title = strtok($section, "\n");
            if (count($sql[1]) !== 1 || count($meta[1]) !== 1) {
                throw new RuntimeException("Section \"$title\": one sql block and one name line expected");
            }
            $recipes[] = ['title' => $title, 'sql' => $sql[1][0], 'name' => $meta[1][0], 'color' => $meta[2][0], 'icon' => $meta[3][0]];
        }
        return $recipes;
    }

    /**
     * The recipes as a data provider, by title.
     *
     * @return array<string, array{0: array{title: string, sql: string, name: string, color: string, icon: string}}>
     */
    public static function recipeProvider()
    {
        $data = [];
        foreach (self::recipes() as $recipe) {
            $data[$recipe['title']] = [$recipe];
        }
        return $data;
    }

    public function testThePageHasRecipesWithDistinctNames()
    {
        $recipes = self::recipes();
        $this->assertGreaterThanOrEqual(20, count($recipes));
        $names = array_column($recipes, 'name');
        $this->assertSame($names, array_unique($names));
        // Every sql block of the page belongs to a recipe
        $this->assertSame(count($recipes), substr_count(file_get_contents(self::DOC), '```sql'));
    }

    #[DataProvider('recipeProvider')]
    public function testRecipeIsAValidInsight($recipe)
    {
        [$name, $color, $sql] = Insight::validate($recipe['name'], $recipe['color'], $recipe['sql']);
        $this->assertSame($recipe['name'], $name);
        $this->assertSame($recipe['sql'], $sql, 'Copied as is');
        $this->assertStringContainsString('{month}', $sql);
        $this->assertStringContainsString('{year}', $sql);
    }

    #[DataProvider('recipeProvider')]
    public function testRecipeIconIsOfferedByThePortal($recipe)
    {
        $module = file_get_contents(__DIR__ . '/../../portal/modules/insightsModule.js');
        $this->assertMatchesRegularExpression('/insightIcons: \[[^\]]*\'' . $recipe['icon'] . '\'/', $module);
    }
}
