<?php

/**
 * Mcp.php
 *
 * MCP server (Model Context Protocol, Streamable HTTP transport) to let Claude
 * analyse the household data: JSON-RPC 2.0 messages on POST /api/mcp, one JSON
 * response per request, no session. The tools are read-only.
 *
 * Authentication: "Authorization: Bearer <API token>" (created in the AI agents
 * tab of the portal, or obtained with OAuth 2.1 by the agents that only take an
 * URL, see OAuth.php), the token in ?token= for the agents that cannot send a
 * header, or a JWT. An administrator enables or disables the server.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Mcp {

    /**
     * Protocol versions supported, most recent first
     */
    private const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * Version of the server
     */
    private const SERVER_VERSION = '1.0.0';

    /**
     * Web sites allowed to call the server from a browser, besides the server itself:
     * the web clients of Claude
     */
    private const ALLOWED_ORIGINS = ['claude.ai', 'claude.com'];

    /**
     * JSON-RPC error codes
     */
    private const INVALID_REQUEST = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS = -32602;

    /**
     * Handle a JSON-RPC message of an MCP client.
     *
     * The members of the message are those sent by the client, of any JSON type: they
     * are checked here (an invalid one gets a JSON-RPC error, not an HTTP one).
     *
     * @param mixed       $jsonrpc Always "2.0"
     * @param mixed       $method  The method (initialize, tools/list, tools/call...)
     * @param mixed       $id      The request id: int or string (none for a notification)
     * @param mixed       $params  The parameters of the method (an object)
     * @param mixed       $result  Response of the client to a server request (unused)
     * @param mixed       $error   Error of the client to a server request (unused)
     * @param string|null $token   API token, for the agents that cannot send a header
     * @return string The JSON-RPC response, or '' (HTTP 202) for a notification
     * @throws Error If disabled (403), unauthenticated (401) or from another site (403)
     */
    #[ApiRoute('/mcp', method: 'POST', public: true, raw: true)]
    public static function handle(mixed $jsonrpc = null, mixed $method = null, mixed $id = null, mixed $params = null, mixed $result = null, mixed $error = null, ?string $token = null): string
    {
        if (!self::enabled()) {
            throw new Error("The MCP server is disabled: an administrator enables it in the AI agents tab", 403);
        }
        self::checkOrigin();
        self::authenticate($token);

        // Notifications and responses of the client: accepted, nothing to answer
        if ($method === null || $id === null) {
            if ($method === null && $result === null && $error === null) {
                return self::error(null, self::INVALID_REQUEST, "Invalid JSON-RPC message");
            }
            http_response_code(202);
            return '';
        }

        if ($jsonrpc !== '2.0' || !is_string($method) || !(is_int($id) || is_string($id))) {
            return self::error($id, self::INVALID_REQUEST, "Invalid JSON-RPC request");
        }
        $params = is_array($params) ? $params : [];

        switch ($method) {
            case 'initialize':
                return self::result($id, self::initialize($params));
            case 'ping':
                return self::result($id, new stdClass());
            case 'tools/list':
                return self::result($id, ['tools' => self::tools()]);
            case 'tools/call':
                return self::callTool($id, $params);
            default:
                return self::error($id, self::METHOD_NOT_FOUND, "Method not found: $method");
        }
    }

    /**
     * State of the MCP server, for the AI agents tab.
     *
     * @return array{enabled: bool} enabled
     */
    #[ApiRoute('/mcp/settings', method: 'GET')]
    public static function settings(): array
    {
        return ['enabled' => self::enabled()];
    }

    /**
     * Enable or disable the MCP server (administrators).
     *
     * @param bool|int|string|null $enabled True to enable it (or "true", "1"...)
     * @return array{enabled: bool} enabled
     */
    #[ApiRoute('/mcp/settings', method: 'PUT')]
    public static function updateSettings(bool|int|string|null $enabled): array
    {
        User::requireAdmin();
        Setting::set('mcp_enabled', filter_var($enabled, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        return ['enabled' => self::enabled()];
    }

    /**
     * Check if the MCP server is enabled (disabled until an administrator enables it).
     *
     * @return bool True if enabled
     */
    public static function enabled(): bool
    {
        return Setting::get('mcp_enabled', '0') === '1';
    }

    /**
     * The server does not open SSE streams: GET is not allowed (MCP specification).
     *
     * @return never
     * @throws Error Always (405)
     */
    #[ApiRoute('/mcp', method: 'GET', public: true)]
    public static function stream(): never
    {
        if (!headers_sent()) {
            header('Allow: POST');
        }
        throw new Error("Method not allowed: use POST", 405);
    }

    /**
     * Refuse the requests of another web site (DNS rebinding): an Origin header,
     * sent by browsers, must be the host of the server or a web client of Claude.
     *
     * @return void
     * @throws Error If the origin is another site
     */
    private static function checkOrigin(): void
    {
        $origin = Webservice::getHeader('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        $allowed = parse_url($origin, PHP_URL_SCHEME) === 'https' && in_array(strtolower((string)$originHost), self::ALLOWED_ORIGINS, true);
        if (!$allowed && (!$originHost || !$host || strcasecmp($originHost, $host) !== 0)) {
            throw new Error("Forbidden origin", 403);
        }
    }

    /**
     * Authenticate the client with an API token or a JWT; the tools then run as
     * this user.
     *
     * @param string|null $queryToken API token given in the URL
     * @return int The user id
     * @throws Error If the token is missing or invalid (401)
     */
    private static function authenticate(?string $queryToken = null): int
    {
        $token = Jwt::getTokenFromHeader() ?: $queryToken;
        $userId = null;
        if ($token) {
            if (str_starts_with($token, ApiToken::PREFIX)) {
                $userId = ApiToken::authenticate($token);
            } else {
                $payload = Jwt::parseJwt($token);
                $userId = $payload === false ? null : (int)$payload->sub;
            }
        }

        if ($userId === null) {
            if (!headers_sent()) {
                // Where the agents that only take an URL learn how to get a token (OAuth 2.1)
                header('WWW-Authenticate: ' . OAuth::challenge());
            }
            throw new Error("Unauthorized - Invalid or missing API token", 401);
        }

        Jwt::actAs($userId);
        return $userId;
    }

    /**
     * Answer to initialize: the protocol version and the capabilities of the server.
     *
     * @param array<mixed> $params The parameters of the client
     * @return array<string, mixed> The result
     */
    private static function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'carbure', 'title' => 'Carbure', 'version' => self::SERVER_VERSION],
            'instructions' => "Carbure holds the bank transactions, budgets and categories of a household (amounts in euros; "
                . "negative amounts are expenses). Use list_categories to know the categories, then the other tools to "
                . "analyse spending, budgets and savings. All the tools are read-only.",
        ];
    }

    /**
     * Definition of the tools (JSON Schema of their arguments).
     *
     * @return list<array<string, mixed>> The tools
     */
    private static function tools(): array
    {
        $month = ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Month (1-12), current month by default'];
        $year = ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100, 'description' => 'Year, current year by default'];
        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];

        return [
            [
                'name' => 'list_categories',
                'title' => 'Categories',
                'description' => 'List the categories (id, name, parent, type: DEBIT = expense, CREDIT = income, HORS-BUDGET = off-budget such as internal transfers or savings).',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'list_transactions',
                'title' => 'Transactions of a month',
                'description' => 'List the transactions of a month, optionally of one category and its sub-categories.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'month' => $month, 'year' => $year,
                    'category_id' => ['type' => 'integer', 'description' => 'Only this category and its sub-categories'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'search_transactions',
                'title' => 'Search transactions',
                'description' => 'Search the transactions whose label contains a text (e.g. a shop or a company), most recent first.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 2, 'description' => 'Text to find in the label'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Maximum number of transactions (100 by default)'],
                ], 'required' => ['query']],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'spending_by_category',
                'title' => 'Totals by category',
                'description' => 'Total amount and number of transactions per category over a period of months (both included), e.g. to compare spending between categories.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$', 'description' => 'First month (YYYY-MM), 11 months ago by default'],
                    'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$', 'description' => 'Last month (YYYY-MM), current month by default'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_budget',
                'title' => 'Budget of a month',
                'description' => 'Budget of a month: for each top-level category (or the sub-categories of category_id), the amount spent and the budget planned.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'month' => $month, 'year' => $year,
                    'category_id' => ['type' => 'integer', 'description' => 'Parent category to detail (top-level categories by default)'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_trends',
                'title' => 'Monthly trends',
                'description' => 'Monthly totals (oldest first): debit (expenses), credit (income), offBudget, planned budget and savings (net amount moved to the savings category).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'description' => 'Number of months (12 by default)'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 120, 'description' => 'Months between the current month and the last month of the period (0 by default)'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'list_rules',
                'title' => 'Categorization rules',
                'description' => 'Automatic categorization rules: a transaction whose label contains the keyword gets the category.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'annotations' => $readOnly,
            ],
        ];
    }

    /**
     * Call a tool: its result is returned as JSON text; an invalid argument is
     * reported as a tool error so that the model can correct it.
     *
     * @param int|string   $id     The request id
     * @param array<mixed> $params name and arguments
     * @return string The JSON-RPC response
     */
    private static function callTool(int|string $id, array $params): string
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $known = array_column(self::tools(), 'name');
        if (!in_array($name, $known, true)) {
            return self::error($id, self::INVALID_PARAMS, "Unknown tool: " . (is_string($name) ? $name : ''));
        }

        try {
            $data = self::runTool($name, $arguments);
            $text = json_encode(Webservice::normalizeNumbers($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return self::result($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]);
        } catch (Error $e) {
            if ($e->getCode() < 400 || $e->getCode() >= 500) {
                throw $e;
            }
            return self::result($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        }
    }

    /**
     * Run a tool.
     *
     * @param string       $name      The tool
     * @param array<mixed> $arguments Its arguments
     * @return mixed The data
     * @throws Error If an argument is invalid
     */
    private static function runTool(string $name, array $arguments): mixed
    {
        $int = fn($key, $default = null) => isset($arguments[$key]) && $arguments[$key] !== '' ? (int)$arguments[$key] : $default;

        switch ($name) {
            case 'list_categories':
                return self::categories();

            case 'list_transactions':
                return self::compact(Transaction::get($int('month'), $int('year'), $int('category_id')));

            case 'search_transactions':
                return self::compact(Transaction::search($arguments['query'] ?? '', $int('limit', 100)));

            case 'spending_by_category':
                return self::spendingByCategory($arguments['from'] ?? null, $arguments['to'] ?? null);

            case 'get_budget':
                return Budget::get($int('month'), $int('year'), $int('category_id', 0));

            case 'get_trends':
                return Budget::getTrends($int('months', 12), $int('offset', 0));

            case 'list_rules':
                return array_map(fn($rule) => ['keyword' => $rule['keyword'], 'category' => $rule['category_name'] ?? $rule['category'] ?? null],
                    Category::getKeywords());
        }
        return null;
    }

    /**
     * Categories with the name of their parent.
     *
     * @return list<array{id: int, name: mixed, parent: mixed, type: mixed}> id, name, parent (name or null), type
     */
    private static function categories(): array
    {
        $categories = Category::get();
        $names = array_column($categories, 'name', 'id');
        return array_map(fn($c) => [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'parent' => (int)$c['parent_category'] !== 0 && (int)$c['parent_category'] !== (int)$c['id'] ? ($names[$c['parent_category']] ?? null) : null,
            'type' => $c['type'],
        ], $categories);
    }

    /**
     * Transactions reduced to what an analysis needs, with the category name.
     *
     * @param array<array<string, mixed>> $transactions Rows of bank_transaction
     * @return array<array<string, mixed>> date, label, amount, category, checked
     */
    private static function compact(array $transactions): array
    {
        $names = [];
        foreach (self::categories() as $category) {
            $names[$category['id']] = $category['parent'] ? $category['parent'] . ' › ' . $category['name'] : $category['name'];
        }
        return array_map(fn($t) => [
            'date' => $t['date'],
            'label' => $t['label'],
            'amount' => (float)$t['amount'],
            'category' => $names[(int)$t['category']] ?? null,
            'checked' => (bool)$t['pointed'],
        ], $transactions);
    }

    /**
     * Total and number of transactions per category over a period.
     *
     * @param mixed $from First month (YYYY-MM), as sent by the client
     * @param mixed $to   Last month (YYYY-MM), as sent by the client
     * @return array<string, mixed> The period and the categories, largest expenses first
     * @throws Error If a month is invalid or the period is reversed
     */
    private static function spendingByCategory(mixed $from, mixed $to): array
    {
        $to = $to ?: date('Y-m');
        $from = $from ?: date('Y-m', strtotime(date('Y-m-01') . ' -11 months'));
        foreach ([$from, $to] as $month) {
            if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw new Error("Months must be in the YYYY-MM format", 400);
            }
        }
        if ($from > $to) {
            throw new Error("'from' must not be after 'to'", 400);
        }

        $start = "$from-01";
        $end = date('Y-m-d', strtotime("$to-01 +1 month"));
        $sql = "SELECT t.category, SUM(t.amount) AS total, COUNT(*) AS transactions
                FROM bank_transaction t
                WHERE t.date >= ? AND t.date < ?
                GROUP BY t.category
                ORDER BY total";
        $rows = Db::execute($sql, "ss", $start, $end)->get_result()->fetch_all(MYSQLI_ASSOC);

        $categories = array_column(self::categories(), null, 'id');
        $result = [];
        foreach ($rows as $row) {
            $category = $categories[(int)$row['category']] ?? null;
            $result[] = [
                'category' => $category ? ($category['parent'] ? $category['parent'] . ' › ' . $category['name'] : $category['name']) : null,
                'type' => $category['type'] ?? null,
                'total' => round((float)$row['total'], 2),
                'transactions' => (int)$row['transactions'],
            ];
        }

        return ['from' => $from, 'to' => $to, 'categories' => $result];
    }

    /**
     * JSON-RPC success response.
     *
     * @param int|string $id     The request id
     * @param mixed      $result The result
     * @return string The response
     */
    private static function result(int|string $id, mixed $result): string
    {
        return self::encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * JSON-RPC error response.
     *
     * @param mixed  $id      The request id (as sent, even if invalid)
     * @param int    $code    The error code
     * @param string $message The message
     * @return string The response
     */
    private static function error(mixed $id, int $code, string $message): string
    {
        return self::encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /**
     * Encode a message (ids and texts are kept as they are).
     *
     * @param array<string, mixed> $message The message
     * @return string The JSON
     */
    private static function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
