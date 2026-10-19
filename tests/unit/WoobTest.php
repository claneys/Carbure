<?php

use Carbure\Lib\Config;
use Carbure\Lib\Logger;
use Carbure\Lib\Woob;
use Carbure\Lib\WoobRepository;
use PHPUnit\Framework\TestCase;

class WoobTest extends TestCase
{
    public function testHistoryIsParsedLineByLine()
    {
        $data = Woob::getBankData('history', '123@bnp');

        // Two transactions in a JSON array + one single object, the invalid line is skipped
        $this->assertCount(3, $data);
        $this->assertSame('PRLV SEPA EDF MDT/123', $data[0]['raw']);
        $this->assertSame('VIR SALAIRE', $data[2]['raw']);
    }

    public function testComing()
    {
        $data = Woob::getBankData('coming', '123@bnp');

        $this->assertCount(2, $data);
        $this->assertSame(12, $data[0]['type']);
    }

    public function testNoOutputReturnsNull()
    {
        $this->assertNull(Woob::getBankData('history', 'empty@bank'));
    }

    public function testErrorWithoutDataThrows()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("woob returned no data: Error(bnp): Unable to load module \"bnp\": No module named 'curl_cffi'");
        Woob::getBankData('history', 'broken@bank');
    }

    public function testEmptyListWithAnErrorThrows()
    {
        // The bank refuses woob: "[]" on stdout, the error on stderr, exit code 0
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('woob returned no data: Bug(bank): 403 Client Error: Forbidden');
        Woob::getBankData('history', 'forbidden@bank');
    }

    public function testNoAccountWithAnErrorThrows()
    {
        try {
            Woob::listAccounts('forbidden');
            $this->fail('No account taken as an empty bank');
        } catch (Exception $e) {
            // The hint woob adds after the error is not its cause
            $this->assertSame('woob returned no account: Bug(forbidden): 403 Client Error: Forbidden', $e->getMessage());
        }
    }

    public function testFailureThrows()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("exit code: 1): AttributeError: 'NoneType' object has no attribute 'iter_accounts'");
        Woob::getBankData('history', 'fail@bank');
    }

    public function testTwoFactorAuthenticationNeedsTheInteractiveMode()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('You have to run bank in interactive mode to perform a two-factor authentication');
        Woob::getBankData('history', '2fa@bank');
    }

    public function testInteractiveModeRunsWoobOnATerminal()
    {
        $messages = [];
        $data = Woob::getBankData('history', '2fa@bank', true, function ($message) use (&$messages) {
            $messages[] = $message;
        });

        // The data is read as without terminal (no "\r\n"), the messages are given without colors
        $this->assertCount(3, $data);
        $this->assertSame('VIR SALAIRE', $data[2]['raw']);
        $this->assertSame(['Veuillez valider la connexion dans votre application bancaire.', 'not json, ignored'], $messages);
    }

    public function testInteractiveModeKeepsTheExitCode()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("exit code: 1): AttributeError: 'NoneType' object has no attribute 'iter_accounts'");
        Woob::getBankData('history', 'fail@bank', true);
    }

    public function testDebugLogsAreGivenLiveWithWoobDebug()
    {
        $lines = [];
        $onDebug = function ($line) use (&$lines) {
            $lines[] = $line;
        };

        // Without woob_debug: nothing
        Woob::getBankData('history', '123@bnp', false, null, $onDebug);
        $this->assertSame([], $lines);

        Config::set('woob_debug', true);
        try {
            $data = Woob::getBankData('history', '123@bnp', false, null, $onDebug);
        } finally {
            Config::set('woob_debug', false);
        }

        $this->assertCount(3, $data);
        $this->assertSame(['DEBUG:woob.browser:Request: GET https://bank.example/accounts', 'DEBUG:woob.browser:Response: 200 OK',
            '2026-10-12 20:40:53,791:DEBUG:woob.backend.bnp.browser:3.7:browsers.py:1040:internal_callback Handle https://bank.example/error with ErrorPage'], $lines);
    }

    public function testDebugLogsKeepTheErrorOfAFailure()
    {
        $lines = [];
        Config::set('woob_debug', true);
        try {
            Woob::getBankData('history', 'fail@bank', false, null, function ($line) use (&$lines) {
                $lines[] = $line;
            });
            $this->fail('woob failed');
        } catch (Exception $e) {
            // The cause, not the debug line written after it
            $this->assertStringEndsWith("exit code: 1): AttributeError: 'NoneType' object has no attribute 'iter_accounts'", $e->getMessage());
        } finally {
            Config::set('woob_debug', false);
        }

        $this->assertContains("AttributeError: 'NoneType' object has no attribute 'iter_accounts'", $lines);
        $this->assertStringContainsString('Stored cookies into storage', end($lines));
    }

    public function testDebugLinesMentioningAnErrorAreNotOne()
    {
        // An empty account with woob debug logs on stderr ("…/error with ErrorPage")
        Config::set('woob_debug', true);
        try {
            $this->assertNull(Woob::getBankData('history', 'empty@bank'));
        } finally {
            Config::set('woob_debug', false);
        }
    }

    public function testCommandRunIsGiven()
    {
        $commands = [];
        Woob::getBankData('history', '123@bnp', false, null, null, function ($command) use (&$commands) {
            $commands[] = $command;
        });

        $this->assertCount(1, $commands);
        $this->assertStringContainsString("bank history '123@bnp' --count", $commands[0]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function loggingLevels(): array
    {
        return [
            'debug' => ['debug', ' --debug -f json'], 'info' => ['info', ' --verbose -f json'],
            'warning' => ['warning', ' -f json'], 'error' => ['error', ' --quiet -f json'],
            'critical' => ['critical', ' --quiet -f json'], 'unknown' => ['loud', ' -f json'],
        ];
    }

    public function testDebugSupersedesTheLoggingLevel()
    {
        $previous = Config::get('woob_logging');
        Config::set('woob_logging', 'error');
        Config::set('woob_debug', true);
        try {
            $command = (new ReflectionMethod(Woob::class, 'buildCommand'))->invoke(null, 'coming', '123@bnp');
        } finally {
            Config::set('woob_debug', false);
            Config::set('woob_logging', $previous);
        }

        // Never "--debug --quiet"
        $this->assertSame(1, substr_count($command, ' --debug'));
        $this->assertStringNotContainsString('--quiet', $command);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loggingLevels')]
    public function testLoggingLevelGivesAWoobOptionNotALogFile(string $level, string $expected)
    {
        $previous = Config::get('woob_logging');
        Config::set('woob_logging', $level);
        try {
            $command = (new ReflectionMethod(Woob::class, 'buildCommand'))->invoke(null, 'history', '1@bnp');
        } finally {
            Config::set('woob_logging', $previous);
        }

        // "--logging <level>" would be read by woob as --logging-file: a file named after the level
        $this->assertStringNotContainsString('--logging', $command);
        $this->assertStringContainsString($expected, $command);
    }

    public function testAccountsOfOneBackend()
    {
        // bank list -b <backend>: the fake woob answers the accounts of the backend asked
        $this->assertSame(['DEMO0000001@cic_pro', '00087654321@cic_pro'], array_column(Woob::listAccounts('cic_pro'), 'id'));
    }

    public function testBackendIsEscaped()
    {
        // Without escaping, the shell would run the injected command
        $marker = carbure_test_tmp('carbure-injection-' . uniqid());
        Woob::listAccounts("x; touch $marker");

        $this->assertFileDoesNotExist($marker);
    }

    public function testBankIdIsEscaped()
    {
        // Without escaping, the shell would run the injected command
        $marker = carbure_test_tmp('carbure-injection-' . uniqid());
        Woob::getBankData('history', "x@bnp; touch $marker");

        $this->assertFileDoesNotExist($marker);
    }

    public function testCommandOptions()
    {
        Config::set('woob_debug', true);
        Config::set('woob_auto_update', true);
        // --auto-update only once the woob repository is trusted (see WoobRepositoryTest)
        $keyrings = WoobRepository::$keyringDir;
        @mkdir($keyrings, 0700, true);
        copy(dirname(__DIR__) . '/fixtures/woob-repository/trusted.gpg', "$keyrings/https___updates_woob_tech_3_main_.gpg");
        try {
            $command = (new ReflectionMethod(Woob::class, 'buildCommand'))->invoke(null, 'history', '1@bnp');
        } finally {
            Config::set('woob_debug', false);
            array_map('unlink', glob("$keyrings/*") ?: []);
        }

        $this->assertStringContainsString("bank history '1@bnp'", $command);
        $this->assertStringContainsString('--auto-update', $command);
        $this->assertStringContainsString('--debug', $command);
        $this->assertStringContainsString('-f json', $command);
    }

    public function testBackendsFileMadePrivate()
    {
        // A file of the banks readable by everyone (copied into a NAS shared folder)
        $home = carbure_test_tmp('carbure-woob-' . uniqid());
        mkdir("$home/.config/woob", 0777, true);
        chmod("$home/.config/woob", 0777);
        file_put_contents("$home/.config/woob/backends", "[bnp]\n");
        chmod("$home/.config/woob/backends", 0644);
        $previous = Config::get('woob_path');
        Config::set('woob_path', "env HOME=$home woob");
        try {
            $this->assertSame("env HOME=$home woob", Woob::path());
            clearstatcache();
            $this->assertSame(0600, fileperms("$home/.config/woob/backends") & 0777);
            $this->assertSame(0700, fileperms("$home/.config/woob") & 0777);
        } finally {
            Config::set('woob_path', $previous);
            unlink("$home/.config/woob/backends");
            rmdir("$home/.config/woob");
            rmdir("$home/.config");
            rmdir($home);
        }
    }

    public function testTheLogsKeepOnlyTheLastErrorLineOutsideOfWoobDebug()
    {
        $loggedOutput = new ReflectionMethod(Woob::class, 'loggedOutput');
        $stderr = "Account FR76 3000 4000 0500 0012 3456 789 balance 1234.56\nAttributeError: login failed\n";

        $this->assertSame('AttributeError: login failed', $loggedOutput->invoke(null, $stderr));
        Config::set('woob_debug', true);
        try {
            $this->assertSame($stderr, $loggedOutput->invoke(null, $stderr));
        } finally {
            Config::set('woob_debug', false);
        }
    }

    public function testAFailureLogsNoOutputOfWoobOutsideOfWoobDebug()
    {
        try {
            Woob::getBankData('history', 'fail@bank');
            $this->fail('woob failed');
        } catch (Exception $e) {
            Logger::flush();
        }
        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        $lines = array_filter(file($file), fn($line) => str_contains($line, 'Error while calling woob (exit code: 1)'));
        $entry = json_decode(end($lines), true);
        $this->assertSame(['stderr' => "AttributeError: 'NoneType' object has no attribute 'iter_accounts'"], $entry['context']);
    }

    public function testAnAccountIsNeverReadAsAnOption()
    {
        // "-x@bnp" given to woob would be one of its options
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid bank account');
        Woob::getBankData('history', '--debug@bnp');
    }

    public function testTheAccountIsFoundInTheListOfItsBackend()
    {
        // First step of a synchronization (#43): only the accounts of its backend, only their id
        $commands = [];
        $account = Woob::findAccount('111@bnp', false, null, null, function ($command) use (&$commands) {
            $commands[] = $command;
        });
        $this->assertSame(['id' => '111@bnp'], $account);
        $this->assertCount(1, $commands);
        $this->assertStringContainsString("bank list -b 'bnp' -f json -s id", $commands[0]);
    }

    public function testAnAccountNotListedByTheBankFails()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Account unlisted@bnp not in the 15 account(s) listed by woob for bnp');
        Woob::findAccount('unlisted@bnp');
    }

    public function testTheListOfTheAccountsFailsWithTheErrorOfWoob()
    {
        // The bank refuses the connection: its error, not "not in the list"
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('woob returned no account: Bug(forbidden): 403 Client Error: Forbidden');
        Woob::findAccount('1@forbidden');
    }

    public function testFindAccountRefusesAnInvalidAccount()
    {
        foreach (['--debug@bnp', 'nobackend', '1@bnp; touch x', '1@-b'] as $bankId) {
            try {
                Woob::findAccount($bankId);
                $this->fail("Account $bankId given to woob");
            } catch (Exception $e) {
                $this->assertStringStartsWith('Invalid bank account', $e->getMessage());
            }
        }
    }

    public function testBackendNamesNeverStartWithADash()
    {
        foreach (['bnp', 'bnp_2', 'bnp-pro', '2bnp'] as $name) {
            $this->assertMatchesRegularExpression(Woob::BACKEND_NAME, $name);
        }
        foreach (['-b', '--debug', 'Bnp', 'b p', ''] as $name) {
            $this->assertDoesNotMatchRegularExpression(Woob::BACKEND_NAME, $name);
        }
    }

    public function testAddBackendKeepsTheCredentialsOffTheCommandLine()
    {
        // The fake woob refuses any setting on its command line ("config add" only installs)
        $file = Woob::backendsFile();
        $this->assertSame(carbure_test_tmp('carbure-fake-woob-backends'), $file);
        file_put_contents($file, "[cic_pro]\n_module = cic\nlogin = jdoe");
        try {
            Woob::addBackend('bnp', 'mybnp', ['login' => '12345678', 'Password' => 'p@ss;%#=[x]', 'website' => '']);

            // Written as "woob config add" writes it: lowercase keys, the other backends kept
            $this->assertSame("[cic_pro]\n_module = cic\nlogin = jdoe\n"
                . "[mybnp]\n_module = bnp\nlogin = 12345678\npassword = p@ss;%#=[x]\nwebsite = \n\n", file_get_contents($file));
            $this->assertSame(0600, fileperms($file) & 0777);
            // woob reads it with RawConfigParser: no inline comment (";", "#"), no interpolation
            // ("%"), split on the first "=": the value is read as written

            // Removed with its credentials (also when woob did not remove it), the others kept
            Woob::removeBackend('mybnp');
            $this->assertSame("[cic_pro]\n_module = cic\nlogin = jdoe\n", file_get_contents($file));
        } finally {
            @unlink($file);
            @unlink(carbure_test_tmp('carbure-fake-woob-removed'));
        }
    }

    public function testAddBackendCreatesAPrivateFile()
    {
        $home = carbure_test_tmp('carbure-woob-' . uniqid());
        $previous = Config::get('woob_path');
        Config::set('woob_path', 'env WOOB_WORKDIR=' . escapeshellarg("$home/woob") . ' woob');
        try {
            (new ReflectionMethod(Woob::class, 'appendBackend'))->invoke(null, Woob::backendsFile(), "[b]\n_module = bnp\n\n");
            $this->assertSame("[b]\n_module = bnp\n\n", file_get_contents("$home/woob/backends"));
            $this->assertSame(0600, fileperms("$home/woob/backends") & 0777);
            $this->assertSame(0700, fileperms("$home/woob") & 0777);
        } finally {
            Config::set('woob_path', $previous);
            @unlink("$home/woob/backends");
            @rmdir("$home/woob");
            @rmdir($home);
        }
    }

    public function testAddBackendRefusals()
    {
        $file = Woob::backendsFile();
        file_put_contents($file, "[taken]\n_module = cic\n");
        try {
            foreach ([
                ['bnp', 'taken', ['login' => '1'], 'Backend "taken" already exists.'],
                ['bnp', '-b', ['login' => '1'], 'Invalid woob module or backend name'],
                ['bnp]', 'x', ['login' => '1'], 'Invalid woob module or backend name'],
                ['bnp', 'x', ['login' => "1\n[other]"], 'Invalid setting login'],
                ['bnp', 'x', ['log in' => '1'], 'Invalid setting log in'],
                // woob would run it as a command to get the value
                ['bnp', 'x', ['password' => '`touch /tmp/x`'], 'Invalid setting password'],
                ['bnp', 'x', ['password' => '`'], 'Invalid setting password'],
            ] as [$module, $backend, $params, $message]) {
                try {
                    Woob::addBackend($module, $backend, $params);
                    $this->fail("Added $backend");
                } catch (Exception $e) {
                    $this->assertSame("woob could not add the bank: $message", $e->getMessage());
                }
            }
            // Nothing written
            $this->assertSame("[taken]\n_module = cic\n", file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function backendsFiles(): array
    {
        return [
            'WOOB_BACKENDS' => ['env WOOB_BACKENDS=/w/banks.ini HOME=/h woob', '/w/banks.ini'],
            'WOOB_WORKDIR' => ["env 'WOOB_WORKDIR=/w/' HOME=/h woob", '/w/backends'],
            'XDG_CONFIG_HOME' => ['env XDG_CONFIG_HOME="/x" HOME=/h woob', '/x/woob/backends'],
            'HOME' => ['env HOME=/data/woob woob', '/data/woob/.config/woob/backends'],
            'MY_WOOB_BACKENDS is not WOOB_BACKENDS' => ['env MY_WOOB_BACKENDS=/w HOME=/h woob', '/h/.config/woob/backends'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backendsFiles')]
    public function testBackendsFileIsFoundAsWoobFindsIt(string $woobPath, string $expected)
    {
        $previous = Config::get('woob_path');
        $environment = getenv('XDG_CONFIG_HOME');
        Config::set('woob_path', $woobPath);
        putenv('XDG_CONFIG_HOME');
        try {
            $this->assertSame($expected, Woob::backendsFile());
        } finally {
            Config::set('woob_path', $previous);
            if ($environment !== false) {
                putenv("XDG_CONFIG_HOME=$environment");
            }
        }
    }
}
