<?php

/**
 * SqlTables.php
 *
 * Tables read by a SELECT query: what follows FROM (in a SELECT), JOIN, TABLE and the
 * commas of a FROM clause, at every level of sub-query. Used to restrict the queries of
 * the insights to the budget tables. The query must not contain a backslash (the reading
 * of a quoted text would then depend on the SQL mode of the server).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class SqlTables {

    /**
     * Words after which a table name comes
     */
    private const BEFORE_TABLE = ['JOIN', 'STRAIGHT_JOIN', 'TABLE'];

    /**
     * Words that end a FROM clause (its commas no longer separate tables): reserved words
     * only, as a table alias that the server accepts must not end it (FOR is read apart)
     */
    private const END_OF_FROM = ['WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'UNION', 'WINDOW', 'INTO', 'LOCK',
        'PROCEDURE', 'FETCH'];

    /**
     * Words after FOR in an index hint of a table (USE INDEX FOR ORDER BY (…)): they do
     * not end the FROM clause nor announce a table
     */
    private const INDEX_HINT_FOR = ['JOIN', 'ORDER', 'GROUP'];

    /**
     * Tokens of the query: quoted identifiers and texts, words, other characters
     *
     * @var list<string>
     */
    private array $tokens;

    /**
     * Index of the current token
     */
    private int $pos = 0;

    /**
     * For each level of parentheses: a SELECT was read, its FROM clause is being read
     *
     * @var list<array{select: bool, from: bool}>
     */
    private array $levels = [['select' => false, 'from' => false]];

    /**
     * The next token is a table reference
     */
    private bool $expectTable = false;

    /**
     * The table references read
     *
     * @var list<string>
     */
    private array $tables = [];

    /**
     * Read the tokens of a query.
     *
     * @param list<string> $tokens The tokens
     */
    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * Tables read by a query, as written: a name without its backticks, a qualified name
     * (`db.table`) or anything else found where a table is expected (a text...).
     *
     * @param string $sql The query
     * @return list<string> The table references, in order
     */
    public static function references(string $sql): array
    {
        $parser = new self(self::tokenize($sql));
        for ($parser->pos = 0; $parser->pos < count($parser->tokens); $parser->pos++) {
            $parser->expectTable ? $parser->table() : $parser->token(strtoupper($parser->tokens[$parser->pos]));
        }
        return $parser->tables;
    }

    /**
     * Split a query into tokens: `identifier`, 'text', "text", words and single characters.
     *
     * @param string $sql The query
     * @return list<string> The tokens
     */
    public static function tokenize(string $sql): array
    {
        preg_match_all('/`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|[A-Za-z0-9_$]+|\S/', $sql, $matches);
        return $matches[0];
    }

    /**
     * Read a token outside a table reference.
     *
     * @param string $word The token in upper case
     * @return void
     */
    private function token(string $word): void
    {
        match (true) {
            $word === '(' => $this->levels[] = ['select' => false, 'from' => false],
            $word === ')' => count($this->levels) > 1 && array_pop($this->levels),
            $word === 'SELECT' => $this->levels[count($this->levels) - 1] = ['select' => true, 'from' => false],
            $word === 'FROM' => $this->from(),
            $word === ',' => $this->expectTable = $this->levels[count($this->levels) - 1]['from'],
            $word === 'FOR' => $this->skipIndexHintFor(),
            in_array($word, self::BEFORE_TABLE, true) => $this->expectTable = true,
            in_array($word, self::END_OF_FROM, true) => $this->levels[count($this->levels) - 1]['from'] = false,
            default => null,
        };
    }

    /**
     * A FROM: the FROM clause of a SELECT, or a word of a function (EXTRACT(… FROM …)).
     *
     * @return void
     */
    private function from(): void
    {
        $level = count($this->levels) - 1;
        if ($this->levels[$level]['select']) {
            $this->levels[$level]['from'] = true;
            $this->expectTable = true;
        }
    }

    /**
     * A FOR: in an index hint (FOR JOIN, FOR ORDER BY, FOR GROUP BY), skip the word that
     * follows; the FROM clause goes on (FOR SYSTEM_TIME also belongs to a table).
     *
     * @return void
     */
    private function skipIndexHintFor(): void
    {
        if (in_array(strtoupper($this->tokens[$this->pos + 1] ?? ''), self::INDEX_HINT_FOR, true)) {
            $this->pos++;
        }
    }

    /**
     * Read a table reference: a sub-query, parentheses around tables, or a table name.
     *
     * @return void
     */
    private function table(): void
    {
        $token = $this->tokens[$this->pos];
        if ($token === '(') {
            // Parentheses around tables (their commas separate tables) or a sub-query
            $this->levels[] = ['select' => false, 'from' => true];
            return;
        }
        $this->expectTable = false;
        if (strtoupper($token) === 'SELECT') {
            $this->token('SELECT');
            return;
        }

        // A qualified name is kept whole: db.table
        $name = $token;
        while (($this->tokens[$this->pos + 1] ?? '') === '.') {
            $name .= '.' . ($this->tokens[$this->pos + 2] ?? '');
            $this->pos += 2;
        }
        $this->tables[] = preg_match('/^`((?:[^`]|``)*)`$/', $name, $match) ? str_replace('``', '`', $match[1]) : $name;
    }
}
