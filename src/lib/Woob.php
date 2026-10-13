<?php

/**
 * Woob.php
 *
 * Woob integration utility class
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Woob {

    /**
     * Seconds of silence from woob before sending an SSE heartbeat
     */
    private const HEARTBEAT_INTERVAL = 15;

    /**
     * Options of woob for each level of woob_logging (woob 3.7: -d, -v, -q, warnings by
     * default). Never "--logging <level>": woob expands it to --logging-file and writes
     * its logs to a file named after the level in the working directory.
     */
    private const LOGGING_OPTIONS = [
        'debug' => ' --debug', 'info' => ' --verbose', 'warning' => '', 'error' => ' --quiet', 'critical' => ' --quiet',
    ];

    /** Log line of woob below the warnings ("2026-10-12 20:40:53,390:DEBUG:woob.config:...") */
    private const LOG_LINE = '/^(\S+ \S+:)?(DEBUG|INFO):/';

    /** An error reported by woob on stderr (a "Bug(<backend>): ..." of a module included) */
    private const ERROR_LINE = '/error|exception|traceback|unable|no module|^Bug\(/im';

    /** Hint woob adds after an error: not its cause */
    private const DEBUG_HINT = '/^Use --debug option to print backtraces/';

    /**
     * Command that runs woob, after making sure that woob will accept to start: it
     * refuses a configuration file of the banks (logins and passwords) readable by
     * the group or the other users, as a file copied into a NAS shared folder often is.
     *
     * @return string The command (woob_path of the configuration)
     * @throws Exception If the file stays readable by other users (ACL of the folder)
     */
    public static function path(): string
    {
        $woob_path = Config::get('woob_path');
        // HOME given in the command (Docker: "env HOME=/data/woob woob"), or the one of PHP
        $home = preg_match('/\bHOME=(\S+)/', (string)$woob_path, $m) ? trim($m[1], '"\'') : getenv('HOME');
        $dir = rtrim((string)$home, '/') . '/.config/woob';
        if ($home && is_dir($dir)) {
            if ((fileperms($dir) & 0077) !== 0) {
                @chmod($dir, 0700);
            }
            $backends = "$dir/backends";
            if (is_file($backends) && (fileperms($backends) & 0077) !== 0) {
                @chmod($backends, 0600);
                clearstatcache(true, $backends);
                if ((fileperms($backends) & 0077) !== 0) {
                    throw new Exception("$backends is readable by other users and its permissions cannot be changed "
                        . "(ACL of a NAS shared folder?): allow only its owner to read it (chmod 600)");
                }
            }
        }
        // An unset woob_path gives an empty command, as before
        return (string)$woob_path;
    }

    /**
     * Whether a woob error asks a two-factor authentication, which woob only starts
     * in interactive mode (a user who waits for it in the portal).
     *
     * @param string|null $error The error message
     * @return bool
     */
    public static function needsTwoFactor(?string $error): bool
    {
        return (bool)preg_match('/interactive mode to perform a two-factor authentication/i', (string)$error);
    }

    /**
     * Call Woob to get the list of transactions from a bank.
     * Uses proc_open to send SSE heartbeats while waiting for woob to finish,
     * preventing proxy/client timeouts on long-running commands.
     *
     * In interactive mode, woob runs on a pseudo-terminal (tools/woob-tty.py): it may then
     * start a two-factor authentication and wait for its validation in the bank app,
     * and each message it writes (other than the data) is given to $onMessage.
     *
     * When woob_debug is enabled, each line of the error output of woob (its debug logs:
     * requests to the bank, Python traceback...) is given live to $onDebug.
     *
     * @param string        $type        The type of transactions to get (coming or history)
     * @param string        $bankId      The bank account identifier
     * @param bool          $interactive Allow woob to ask a two-factor authentication
     * @param callable|null $onMessage   Receives each message of woob (interactive mode)
     * @param callable|null $onDebug     Receives each line of the debug logs of woob (woob_debug)
     * @param callable|null $onCommand   Receives the system command run (shown in the sync log)
     * @return list<mixed>|null The transaction data or null if no data
     * @throws Exception If woob fails, or reports an error without returning data
     */
    public static function getBankData(string $type, string $bankId, bool $interactive = false, ?callable $onMessage = null, ?callable $onDebug = null, ?callable $onCommand = null): ?array
    {
        $command = self::buildCommand($type, $bankId);
        if ($interactive) {
            $command = self::interactiveCommand($command);
        }
        Logger::debug("Calling woob : $command");
        if ($onCommand !== null) {
            $onCommand($command);
        }

        $stderr = '';
        $stdout = self::executeWithHeartbeat($command, $stderr, true, $interactive ? $onMessage : null,
            Config::get('woob_debug') ? $onDebug : null);
        $data = self::parseOutput($type, $stdout);
        self::failOnEmptyWithError($data, $stderr, "woob returned no data");

        return $data;
    }

    /**
     * List the bank accounts of every configured woob backend, or of one of them.
     *
     * @param string|null $backend Only the accounts of this woob backend (-b), else of every one
     * @return list<array<mixed>> The accounts: id ("<account>@<backend>"), label, balance, currency...
     * @throws Exception If woob fails or returns nothing while reporting an error
     */
    public static function listAccounts(?string $backend = null): array
    {
        $woob_path = self::path();
        $only = $backend === null ? '' : ' -b ' . escapeshellarg($backend);
        $command = "$woob_path bank list$only -f json" . self::loggingOptions();
        Logger::debug("Calling woob : $command");

        // Plain JSON request: no Server-Sent Events heartbeat in the output
        $stderr = '';
        $stdout = self::executeWithHeartbeat($command, $stderr, false);
        $accounts = self::parseOutput('accounts', $stdout);
        self::failOnEmptyWithError($accounts, $stderr, "woob returned no account");

        return array_values(array_filter($accounts ?? [], fn($a) => is_array($a) && !empty($a['id'])));
    }

    /**
     * List the bank backends configured in woob (only their name and module:
     * the configuration, which contains the bank login, is never returned).
     *
     * @return list<array{name: mixed, module: mixed}> The backends (names given by woob)
     */
    public static function listBackends(): array
    {
        $woob_path = self::path();
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config list CapBank -f json", $stderr, false);

        $backends = [];
        foreach (self::parseOutput('backends', $stdout) ?? [] as $row) {
            if (is_array($row) && !empty($row['Name'])) {
                $backends[] = ['name' => $row['Name'], 'module' => $row['Module'] ?? $row['Name']];
            }
        }
        usort($backends, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $backends;
    }

    /**
     * List the bank modules supported by woob (cached for a day: the list only
     * changes with woob updates).
     *
     * @return list<array{module: mixed, description: mixed}> The modules (names given by woob)
     */
    public static function listBankModules(): array
    {
        $cacheKey = 'carbure_woob_bank_modules';
        if (self::apcuEnabled()) {
            $cached = apcu_fetch($cacheKey, $found);
            if ($found) {
                return $cached;
            }
        }

        $modules = self::fetchBankModules();

        if ($modules && self::apcuEnabled()) {
            apcu_store($cacheKey, $modules, 86400);
        }

        return $modules;
    }

    /**
     * Whether the APCu cache can be used.
     *
     * @return bool
     */
    private static function apcuEnabled(): bool
    {
        return function_exists('apcu_fetch') && ini_get('apc.enabled');
    }

    /**
     * Ask woob for the bank modules it supports, sorted by name.
     *
     * @return list<array{module: mixed, description: mixed}> The modules (names given by woob)
     */
    private static function fetchBankModules(): array
    {
        $woob_path = self::path();
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config modules CapBank -f json", $stderr, false);

        $modules = [];
        foreach (self::parseOutput('modules', $stdout) ?? [] as $row) {
            if (is_array($row) && !empty($row['Name'])) {
                $modules[] = ['module' => $row['Name'], 'description' => $row['Description'] ?? ''];
            }
        }
        usort($modules, fn($a, $b) => strcasecmp($a['description'] ?: $a['module'], $b['description'] ?: $b['module']));

        return $modules;
    }

    /**
     * Settings asked by a woob bank module (login, password, website...), to
     * build the form of the portal. Nothing secret is returned.
     *
     * @param string $module The woob module (e.g. bnp)
     * @return array{module: mixed, description: string, fields: list<array{key: string, label: string,
     *               description: string, default: string, required: bool, masked: bool, regexp: string|null,
     *               choices: list<array{value: string, label: string}>}>} The module and its fields
     * @throws Exception If woob does not know the module
     */
    public static function moduleFields(string $module): array
    {
        $info = self::moduleInfo($module);
        // woob only describes the settings of an installed module: install it first
        if (!isset($info['config'])) {
            self::installModule($module);
            $info = self::moduleInfo($module);
        }

        $fields = [];
        foreach (($info['config'] ?? []) as $key => $field) {
            $choices = [];
            foreach ((array)($field['choices'] ?? []) as $value => $label) {
                // woob gives either {value: label} or a list of values
                $choices[] = is_int($value) && !is_array($label)
                    ? ['value' => (string)$label, 'label' => (string)$label]
                    : ['value' => (string)$value, 'label' => (string)$label];
            }
            // Booleans are offered as y/n choices: false must stay "n", not the first choice
            $default = $field['default'] ?? '';
            if (is_bool($default)) {
                $default = $default ? 'y' : 'n';
            }
            $fields[] = [
                'key' => (string)$key,
                'label' => (string)($field['label'] ?? $key),
                'description' => (string)($field['description'] ?? ''),
                'default' => is_scalar($default) ? (string)$default : '',
                'required' => !empty($field['required']),
                'masked' => !empty($field['masked']),
                'regexp' => is_string($field['regexp'] ?? null) ? $field['regexp'] : null,
                'choices' => $choices,
            ];
        }

        return ['module' => $info['name'], 'description' => (string)($info['description'] ?? ''), 'fields' => $fields];
    }

    /**
     * Description of a woob module (with its settings once installed).
     *
     * @param string $module The woob module
     * @return array<mixed> The description given by "woob config info"
     * @throws Exception If woob does not know the module
     */
    private static function moduleInfo(string $module): array
    {
        $woob_path = self::path();
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config info " . escapeshellarg($module) . " -f json", $stderr, false);
        foreach (self::parseOutput('module', $stdout) ?? [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                return $row;
            }
        }
        throw new Exception("woob does not know the module $module" . self::lastLine($stderr ?: $stdout));
    }

    /**
     * Install a woob module: "config add" installs it, then stops at the first
     * setting it asks for (no input). A backend created anyway (module without
     * settings) is removed.
     *
     * @param string $module The woob module
     * @return void
     */
    private static function installModule(string $module): void
    {
        $woob_path = self::path();
        $probe = 'carbure_install_probe';
        $process = proc_open("$woob_path config add " . escapeshellarg($module) . ' ' . escapeshellarg($probe) . ' < /dev/null',
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (stripos($stdout, 'successfully added') !== false) {
            shell_exec("$woob_path config remove " . escapeshellarg($probe) . ' < /dev/null 2>&1');
        }
    }

    /**
     * Configure a bank backend in woob with the settings typed in the portal
     * (woob keeps them; Carbure does not store them).
     *
     * @param string $module  The woob module
     * @param string $backend The backend name
     * @param array<string, string> $params The settings: key => value
     * @return void
     * @throws Exception If woob refuses the backend
     */
    public static function addBackend(string $module, string $backend, array $params): void
    {
        // woob reads at most 2 arguments: the module, then the backend name and the
        // key=value settings together, quoted (it splits them on spaces)
        $settings = [$backend];
        foreach ($params as $key => $value) {
            $settings[] = "$key=$value";
        }
        $woob_path = self::path();
        $stderr = '';
        // The settings are not logged: they contain the bank credentials
        $process = proc_open("$woob_path config add " . escapeshellarg($module) . ' ' . escapeshellarg('"' . implode(' ', $settings) . '"') . " < /dev/null",
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new Exception("Unable to run woob");
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        // Success is announced by woob; anything else (missing setting asked, module
        // not loaded, name taken...) is a failure
        if ($code !== 0 || stripos($stdout, 'successfully added') === false) {
            Logger::error("woob could not add the backend $backend ($module)");
            throw new Exception("woob could not add the bank" . self::lastLine($stderr ?: $stdout));
        }
    }

    /**
     * Remove a backend from woob (and the credentials woob kept for it).
     *
     * @param string $backend The backend name
     * @return void
     */
    public static function removeBackend(string $backend): void
    {
        $woob_path = self::path();
        shell_exec("$woob_path config remove " . escapeshellarg($backend) . ' < /dev/null 2>&1');
    }

    /**
     * woob may exit with 0 when a backend cannot be loaded or the bank refuses it, with
     * no data or an empty list ("[]") on stdout: nothing + an error on stderr is a
     * failure (e.g. "Bug(bnp): 403 Client Error: Forbidden"), not an empty result.
     *
     * @param array<mixed>|null $data    The data read from stdout
     * @param string            $stderr  The error output of woob
     * @param string            $message The message of the failure, followed by the woob error
     * @return void
     * @throws Exception If there is no data and woob reported an error
     */
    private static function failOnEmptyWithError(?array $data, string $stderr, string $message): void
    {
        if (!$data && preg_match(self::ERROR_LINE, self::withoutLogs($stderr))) {
            Logger::error($message, ["stderr" => $stderr]);
            throw new Exception($message . self::lastLine($stderr));
        }
    }

    /**
     * Last non empty line of an output that is not a debug or info log of woob (the
     * cause of an error comes before woob logs that it stored the session...), nor the
     * hint woob adds after an error, prefixed with ": " (empty string if none).
     *
     * @param string $output The command output
     * @return string The suffix to add to an error message
     */
    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", self::withoutLogs($output))),
            fn(string $line): bool => $line !== '' && !preg_match(self::DEBUG_HINT, $line)));
        return $lines ? ': ' . mb_substr(end($lines), 0, 200) : '';
    }

    /**
     * Build the woob CLI command string.
     *
     * @param string $type  The type of transactions to get (coming or history)
     * @param string $bankId The bank account identifier
     * @return string The full command to execute
     */
    private static function buildCommand(string $type, string $bankId): string
    {
        $woob_path = self::path();
        $woob_transactions = Config::get('woob_transactions');
        $woob_auto_update = Config::get('woob_auto_update');

        $auto_update_flag = $woob_auto_update ? " --auto-update" : "";

        $bankId = escapeshellarg($bankId);

        return "$woob_path bank $type $bankId --count $woob_transactions$auto_update_flag" . self::loggingOptions()
            . " -f json -s date,rdate,amount,raw,type,category,card";
    }

    /**
     * Options of woob for its logs: --debug when woob_debug is enabled (it supersedes the
     * level: never "--debug --quiet"), else the level of woob_logging (nothing for an
     * unknown level).
     *
     * @return string The options, with a leading space
     */
    private static function loggingOptions(): string
    {
        if (Config::get('woob_debug')) {
            return self::LOGGING_OPTIONS['debug'];
        }
        return self::LOGGING_OPTIONS[(string)Config::get('woob_logging')] ?? '';
    }

    /**
     * An output of woob without its debug and info log lines (they may mention
     * "error" without being one: a page named error, a retry...).
     *
     * @param string $output The error output of woob
     * @return string
     */
    private static function withoutLogs(string $output): string
    {
        return implode("\n", array_filter(explode("\n", $output), fn(string $line): bool => !preg_match(self::LOG_LINE, $line)));
    }

    /**
     * Command that runs a woob command on a pseudo-terminal (interactive mode).
     *
     * @param string $command The woob command
     * @return string The command run through tools/woob-tty.py
     */
    private static function interactiveCommand(string $command): string
    {
        return 'python3 ' . escapeshellarg(dirname(__DIR__, 2) . '/tools/woob-tty.py') . ' ' . escapeshellarg($command);
    }

    /**
     * Give the complete lines of a woob output that are not data (JSON) to a callback,
     * without terminal colors. The incomplete last line stays in the buffer.
     *
     * @param string   $buffer    The output not handled yet (updated)
     * @param callable $onMessage Receives each message
     * @param bool     $data      The output contains data (JSON lines, skipped)
     * @param int      $maxLength Maximum length of a message
     * @return void
     */
    private static function emitMessages(string &$buffer, callable $onMessage, bool $data = true, int $maxLength = 300): void
    {
        while (($end = strpos($buffer, "\n")) !== false) {
            $line = trim(preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', substr($buffer, 0, $end)));
            $buffer = substr($buffer, $end + 1);
            if ($line !== '' && (!$data || ($line[0] !== '[' && $line[0] !== '{'))) {
                $onMessage(mb_substr($line, 0, $maxLength));
            }
        }
    }

    /**
     * Execute a shell command with SSE heartbeats sent during execution.
     *
     * @param string        $command   The shell command to execute
     * @param string        $stderr    Receives the error output of the command
     * @param bool          $heartbeat Send SSE heartbeats while waiting (stream routes only)
     * @param callable|null $onMessage Receives each line of the output that is not data
     * @param callable|null $onError   Receives each line of the error output
     * @return string The stdout output of the command
     * @throws Exception If the process fails to start or exits with a non-zero code
     */
    private static function executeWithHeartbeat(string $command, string &$stderr = '', bool $heartbeat = true, ?callable $onMessage = null, ?callable $onError = null): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception("Failed to start woob process");
        }

        fclose($pipes[0]);

        $output = self::readOutputs([1 => $pipes[1], 2 => $pipes[2]], $heartbeat, [1 => $onMessage, 2 => $onError]);

        $stdout = $output[1];
        $stderr = $output[2];
        self::checkExitCode(proc_close($process), $stdout, $stderr);

        return $stdout;
    }

    /**
     * Read stdout and stderr of a process until both are closed, sending a heartbeat
     * each time woob stays silent for HEARTBEAT_INTERVAL seconds.
     *
     * @param array<int, resource>       $open      The pipes to read: 1 (stdout), 2 (stderr)
     * @param bool                       $heartbeat Send SSE heartbeats while waiting
     * @param array<int, callable|null>  $callbacks Receive the lines of each pipe (or null)
     * @return array<int, string> The output of each pipe: 1 (stdout), 2 (stderr)
     */
    private static function readOutputs(array $open, bool $heartbeat, array $callbacks): array
    {
        $output = [1 => '', 2 => ''];
        $pending = [1 => '', 2 => ''];

        while (!empty($open)) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, self::HEARTBEAT_INTERVAL) === 0) {
                if ($heartbeat) {
                    Webservice::sendHeartbeat();
                }
                continue;
            }

            // In the order of the pipes, as given back by stream_select()
            foreach ($open as $index => $pipe) {
                if (in_array($pipe, $read, true)) {
                    self::readChunk($index, $open, $output, $pending, $callbacks[$index]);
                }
            }
        }

        return $output;
    }

    /**
     * Read the next chunk of a pipe (closed at its end) and give its complete lines
     * to the callback of the pipe.
     *
     * @param int                       $index    The pipe: 1 (stdout), 2 (stderr)
     * @param array<int, resource>      $open     The pipes still open (updated)
     * @param array<int, string>        $output   The output read so far (updated)
     * @param array<int, string>        $pending  The output not given to the callback yet (updated)
     * @param callable|null             $callback Receives the lines of the pipe
     * @return void
     */
    private static function readChunk(int $index, array &$open, array &$output, array &$pending, ?callable $callback): void
    {
        $pipe = $open[$index];
        $chunk = fread($pipe, 8192);
        if ($chunk === '' || $chunk === false) {
            fclose($pipe);
            unset($open[$index]);
            $chunk = "\n";
        } else {
            $output[$index] .= $chunk;
        }
        // stdout: messages besides the data; stderr: logs, kept longer (requests, tracebacks)
        if ($callback !== null) {
            $pending[$index] .= $chunk;
            self::emitMessages($pending[$index], $callback, $index === 1, $index === 1 ? 300 : 2000);
        }
    }

    /**
     * Log the output of woob (woob_debug) and fail on a non-zero exit code.
     *
     * @param int    $return_var The exit code of woob
     * @param string $stdout     The standard output
     * @param string $stderr     The error output
     * @return void
     * @throws Exception If woob exited with a non-zero code
     */
    private static function checkExitCode(int $return_var, string $stdout, string $stderr): void
    {
        if (Config::get('woob_debug')) {
            Logger::debug("Woob stdout", ["stdout" => $stdout]);
            Logger::debug("Woob stderr", ["stderr" => $stderr]);
        }

        if ($return_var != 0) {
            Logger::error("Error while calling woob (exit code: $return_var)", ["stdout" => $stdout, "stderr" => $stderr]);

            // The last line of the error output usually gives the cause (e.g. a Python exception)
            throw new Exception("Error while calling woob (exit code: $return_var)" . self::lastLine($stderr));
        }
    }

    /**
     * Parse the JSON output returned by woob.
     *
     * @param string $type   The transaction type (for logging)
     * @param string $stdout The raw stdout from the woob process
     * @return list<mixed>|null The parsed transaction data or null if empty
     */
    private static function parseOutput(string $type, string $stdout): ?array
    {
        $lines = array_filter(explode("\n", trim($stdout)));

        if (count($lines) === 0) {
            Logger::warn("No data received from the bank");
            return null;
        }

        $data = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            // Not JSON, or an empty list ("[]": nothing, not one empty row)
            if ($decoded === null || $decoded === []) {
                continue;
            }
            if (isset($decoded[0]) && is_array($decoded[0])) {
                $data = array_merge($data, $decoded);
            } else {
                $data[] = $decoded;
            }
        }

        Logger::debug("Synchronized $type: " . count($data) . " transactions");
        return $data;
    }
}
