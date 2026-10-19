<?php

/**
 * Category.php
 * *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Error;

final class Category {

    /**
     * Get all the categories available.
     *
     * @return list<array<string, mixed>> The list of categories
     */
    #[ApiRoute('/category', method: 'GET')]
    public static function get(): array
    {
        // Get the category
        $sql = "SELECT * FROM bank_transaction_category";
        $result = Db::select($sql);

        $categories = array();

        // Collect the rows
        if ($result->num_rows > 0) {

            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }
        }

        return $categories;
    }

    /**
     * Types of category
     */
    private const TYPES = ['DEBIT', 'CREDIT', 'HORS-BUDGET'];

    /**
     * Icon of a category (SF Symbols name used by the iOS app, may be empty)
     */
    public const ICON_PATTERN = '/^[a-z0-9._]{0,50}$/';

    /**
     * Color of a category (SwiftUI name or hex, may be empty)
     */
    public const COLOR_PATTERN = '/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{0,30})$/';

    /**
     * Validate the fields of a category and return them normalized.
     *
     * @param int|null        $id     The category being modified (null for a new one)
     * @param string          $name   The name
     * @param int|string|null $parent The parent category (0, '' or null for a top-level category)
     * @param string          $type   DEBIT, CREDIT or HORS-BUDGET
     * @param string|null     $icon   The icon (SF Symbols name used by the iOS app)
     * @param string|null     $color  The color (SwiftUI name or hex)
     * @return array{string, int, string, string, string} [name, parent, type, icon, color]
     * @throws Error If a field is invalid
     */
    private static function validate(?int $id, string $name, int|string|null $parent, string $type, ?string $icon, ?string $color): array
    {
        $name = trim($name);
        $parent = (int)$parent;
        if ($name === '' || mb_strlen($name) > 50) {
            throw new Error("The name must contain 1 to 50 characters", 400);
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new Error("Invalid category type", 400);
        }
        if (!preg_match(self::ICON_PATTERN, (string)$icon) || !preg_match(self::COLOR_PATTERN, (string)$color)) {
            throw new Error("Invalid icon or color", 400);
        }

        $duplicate = Db::queryOne("SELECT id FROM bank_transaction_category WHERE name = ? AND id <> ?", "si", $name, $id ?? -1);
        if ($duplicate) {
            throw new Error("A category with this name already exists", 409);
        }

        if ($parent !== 0) {
            self::validateParent($id, $parent);
        }

        return [$name, $parent, $type, (string)$icon, (string)$color];
    }

    /**
     * Check the parent of a sub-category: one level of sub-categories, so the parent
     * must be a top-level category and the category must have no sub-category itself.
     *
     * @param int|null $id     The category (null for a new one)
     * @param int      $parent The parent category (not 0)
     * @return void
     * @throws Error If the parent is not a top-level category, or the category has sub-categories
     */
    private static function validateParent(?int $id, int $parent): void
    {
        $row = Db::queryOne("SELECT parent_category FROM bank_transaction_category WHERE id = ?", "i", $parent);
        if (!$row || (int)$row['parent_category'] !== 0 || $parent === (int)$id) {
            throw new Error("The parent must be a top-level category", 400);
        }
        if ($id !== null && Db::queryOne("SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> ? LIMIT 1", "ii", $id, $id)) {
            throw new Error("A category with sub-categories cannot become a sub-category", 400);
        }
    }

    /**
     * Create a category.
     *
     * @param string          $name            The name
     * @param string          $type            DEBIT, CREDIT or HORS-BUDGET
     * @param int|string|null $parent_category The parent category (0 for a top-level category)
     * @param string|null     $icon            The icon (SF Symbols name)
     * @param string|null     $color           The color (SwiftUI name or hex)
     * @return array<string, mixed> The category
     * @throws Error If invalid or the name already exists
     */
    #[ApiRoute('/category', method: 'POST', admin: true)]
    public static function create(string $name, string $type, int|string|null $parent_category = 0, ?string $icon = '', ?string $color = ''): array
    {
        [$name, $parent, $type, $icon, $color] = self::validate(null, $name, $parent_category, $type, $icon, $color);

        $stmt = Db::execute("INSERT INTO bank_transaction_category (name, parent_category, type, icon, color) VALUES (?, ?, ?, ?, ?)",
            "sisss", $name, $parent, $type, $icon, $color);

        return ['id' => $stmt->insert_id, 'name' => $name, 'parent_category' => $parent, 'type' => $type, 'icon' => $icon, 'color' => $color];
    }

    /**
     * Modify a category.
     *
     * @param int             $id              The category
     * @param string          $name            The name
     * @param string          $type            DEBIT, CREDIT or HORS-BUDGET
     * @param int|string|null $parent_category The parent category (0 for a top-level category)
     * @param string|null     $icon            The icon (SF Symbols name)
     * @param string|null     $color           The color (SwiftUI name or hex)
     * @return array<string, mixed> The category
     * @throws Error If not found, protected, invalid or the name already exists
     */
    #[ApiRoute('/category', method: 'PUT', admin: true)]
    public static function update(int $id, string $name, string $type, int|string|null $parent_category = 0, ?string $icon = '', ?string $color = ''): array
    {
        self::existing($id);
        [$name, $parent, $type, $icon, $color] = self::validate($id, $name, $parent_category, $type, $icon, $color);

        Db::execute("UPDATE bank_transaction_category SET name = ?, parent_category = ?, type = ?, icon = ?, color = ? WHERE id = ?",
            "sisssi", $name, $parent, $type, $icon, $color, $id);

        return ['id' => $id, 'name' => $name, 'parent_category' => $parent, 'type' => $type, 'icon' => $icon, 'color' => $color];
    }

    /**
     * Delete a category: its transactions become uncategorized, its budgets and
     * categorization rules are deleted.
     *
     * @param int $id The category
     * @return bool True if deleted
     * @throws Error If not found, protected or with sub-categories
     */
    #[ApiRoute('/category', method: 'DELETE', admin: true)]
    public static function delete(int $id): bool
    {
        self::existing($id);
        if (Db::queryOne("SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> ? LIMIT 1", "ii", $id, $id)) {
            throw new Error("Delete or move its sub-categories first", 409);
        }

        Db::execute("UPDATE bank_transaction SET category = 0 WHERE category = ?", "i", $id);
        Db::execute("DELETE FROM budget WHERE category = ?", "i", $id);
        Db::execute("DELETE FROM bank_transaction_category WHERE id = ?", "i", $id);

        return true;
    }

    /**
     * Check that a category exists and can be modified.
     *
     * @param int $id The category
     * @return void
     * @throws Error If not found or protected
     */
    private static function existing(int $id): void
    {
        if ($id === 0) {
            throw new Error("The default category cannot be modified", 400);
        }
        if (!Db::queryOne("SELECT id FROM bank_transaction_category WHERE id = ?", "i", $id)) {
            throw new Error("Category not found", 404);
        }
    }

    /**
     * Get the automatic categorization rules (keywords found in the labels).
     *
     * @return list<array<string, mixed>> The rules with their category name, sorted by category
     */
    #[ApiRoute('/category/keyword', method: 'GET')]
    public static function getKeywords(): array
    {
        $sql = "SELECT k.id, k.keyword, k.category, c.name AS category_name, k.notify
                FROM bank_transaction_category_keyword k
                JOIN bank_transaction_category c ON c.id = k.category
                ORDER BY c.name, k.keyword";
        return Db::select($sql, "")->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Add an automatic categorization rule.
     *
     * @param string               $keyword  The text to find in the transaction labels (stored in upper case)
     * @param int                  $category The category to set
     * @param bool|int|string|null $notify   Notify the household when a new transaction matches (optional, "true"/"false" accepted)
     * @return array<string, mixed> The rule, with applied: the transactions of the history recategorized
     * @throws Error If the keyword is empty or already exists, or the category does not exist
     */
    #[ApiRoute('/category/keyword', method: 'POST', admin: true)]
    public static function createKeyword(string $keyword, int $category, bool|int|string|null $notify = false): array
    {
        // Labels are stored in upper case
        $keyword = strtoupper(trim($keyword));
        if ($keyword === '' || strlen($keyword) > 60) {
            throw new Error("The keyword must contain 1 to 60 characters", 400);
        }

        if (!Db::queryOne("SELECT id FROM bank_transaction_category WHERE id=?", "i", $category)) {
            throw new Error("Category not found", 404);
        }

        if (Db::queryOne("SELECT id FROM bank_transaction_category_keyword WHERE keyword=?", "s", $keyword)) {
            throw new Error("This keyword already exists", 409);
        }

        $notify = filter_var($notify, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $stmt = $notify
            ? Db::execute("INSERT INTO bank_transaction_category_keyword (keyword, category, notify) VALUES (?, ?, 1)", "si", $keyword, $category)
            : Db::execute("INSERT INTO bank_transaction_category_keyword (keyword, category) VALUES (?, ?)", "si", $keyword, $category);

        $id = $stmt->insert_id;
        // The rule applies at once to the whole history
        $applied = self::applyRule($keyword, $category);

        return ['id' => $id, 'keyword' => $keyword, 'category' => $category, 'notify' => $notify, 'applied' => $applied];
    }

    /**
     * Modify a categorization rule (administrators).
     *
     * @param int                  $id       The rule
     * @param string               $keyword  The keyword found in the labels
     * @param int                  $category The category given to the transactions
     * @param bool|int|string|null $notify   Notify the household when a new transaction matches ("true"/"false" accepted)
     * @return array<string, mixed> The rule, with applied: the transactions of the history recategorized
     * @throws Error If not found, invalid or the keyword already exists
     */
    #[ApiRoute('/category/keyword', method: 'PUT', admin: true)]
    public static function updateKeyword(int $id, string $keyword, int $category, bool|int|string|null $notify = false): array
    {
        if (!Db::queryOne("SELECT id FROM bank_transaction_category_keyword WHERE id=?", "i", $id)) {
            throw new Error("Rule not found", 404);
        }
        $keyword = strtoupper(trim($keyword));
        if ($keyword === '' || strlen($keyword) > 60) {
            throw new Error("The keyword must contain 1 to 60 characters", 400);
        }
        if (!Db::queryOne("SELECT id FROM bank_transaction_category WHERE id=?", "i", $category)) {
            throw new Error("Category not found", 404);
        }
        if (Db::queryOne("SELECT id FROM bank_transaction_category_keyword WHERE keyword=? AND id<>?", "si", $keyword, $id)) {
            throw new Error("This keyword already exists", 409);
        }
        $notify = filter_var($notify, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        Db::execute("UPDATE bank_transaction_category_keyword SET keyword=?, category=?, notify=? WHERE id=?", "siii", $keyword, $category, $notify, $id);
        $applied = self::applyRule($keyword, $category);

        return ['id' => $id, 'keyword' => $keyword, 'category' => $category, 'notify' => $notify, 'applied' => $applied];
    }

    /**
     * Number of transactions whose label contains the keyword of a rule
     * (administrators).
     *
     * @param int $id The rule
     * @return array{matching: int, categorized: int} matching (transactions), categorized (with the category of the rule)
     * @throws Error If not found
     */
    #[ApiRoute('/category/keyword/count', method: 'GET', admin: true)]
    public static function countKeyword(int $id): array
    {
        $rule = Db::queryOne("SELECT keyword, category FROM bank_transaction_category_keyword WHERE id=?", "i", $id);
        if (!$rule) {
            throw new Error("Rule not found", 404);
        }
        $pattern = '%' . addcslashes($rule['keyword'], '%_\\') . '%';
        $row = Db::queryOne("SELECT COUNT(*) AS matching, SUM(category = ?) AS categorized FROM bank_transaction WHERE label LIKE ?",
            "is", $rule['category'], $pattern);
        return ['matching' => (int)$row['matching'], 'categorized' => (int)$row['categorized']];
    }

    /**
     * Delete an automatic categorization rule.
     *
     * @param int $id The rule id
     * @return bool True if deleted
     * @throws Error If the rule does not exist
     */
    #[ApiRoute('/category/keyword', method: 'DELETE', admin: true)]
    public static function deleteKeyword(int $id): bool
    {
        $stmt = Db::execute("DELETE FROM bank_transaction_category_keyword WHERE id=?", "i", $id);

        if ($stmt->affected_rows === 0) {
            throw new Error("Rule not found", 404);
        }

        return true;
    }

    /**
     * Apply the rules to every transaction of the database (categorized ones
     * included): the first rule found in a label gives its category.
     *
     * @return array{updated: int} The number of transactions whose category changed
     */
    #[ApiRoute('/category/keyword/apply', method: 'POST', admin: true)]
    public static function applyKeywords(): array
    {
        $keywords = self::loadKeywords();
        $updated = 0;
        foreach (Db::select("SELECT id, label, category FROM bank_transaction", "")->fetch_all(MYSQLI_ASSOC) as $row) {
            $category = self::find($row['label'], $keywords);
            if ($category !== null && (int)$category !== (int)$row['category']) {
                Db::execute("UPDATE bank_transaction SET category = ? WHERE id = ?", "ii", $category, $row['id']);
                $updated++;
            }
        }
        return ['updated' => $updated];
    }

    /**
     * Apply one rule to every transaction whose label contains its keyword
     * (categorized ones included).
     *
     * @param string $keyword  The keyword (upper case)
     * @param int    $category The category of the rule
     * @return int The number of transactions whose category changed
     */
    private static function applyRule(string $keyword, int $category): int
    {
        $pattern = '%' . addcslashes($keyword, '%_\\') . '%';
        return (int)Db::execute("UPDATE bank_transaction SET category = ? WHERE label LIKE ? AND category <> ?",
            "isi", $category, $pattern, $category)->affected_rows;
    }

    /**
     * Get the category of a transaction based on its label.
     *
     * @param string                          $label    The label of the transaction
     * @param list<array<string, mixed>>|null $keywords The keywords already loaded (loaded when null)
     * @return int|null The category id or null if not found
     */
    public static function find(string $label, ?array $keywords = null): ?int
    {
        $keywords ??= self::loadKeywords();

        // The first keyword found in the label gives the category
        foreach ($keywords as $row) {
            if (strpos($label, $row["keyword"]) !== false) {
                return (int)$row["category"];
            }
        }

        return null;
    }

    /**
     * Load all the categorization keywords, in rule creation order.
     *
     * @return list<array<string, mixed>> The rows (keyword, category)
     */
    public static function loadKeywords(): array
    {
        $sql = "SELECT keyword, category FROM bank_transaction_category_keyword ORDER BY id";
        return Db::select($sql, "")->fetch_all(MYSQLI_ASSOC);
    }
}
