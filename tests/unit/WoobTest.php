<?php

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

    public function testBankIdIsEscaped()
    {
        // Without escaping, the shell would run the injected command
        $marker = sys_get_temp_dir() . '/carbure-injection-' . uniqid();
        Woob::getBankData('history', "x@bnp; touch $marker");

        $this->assertFileDoesNotExist($marker);
    }

    public function testCommandOptions()
    {
        Config::set('woob_debug', true);
        Config::set('woob_auto_update', true);
        try {
            $command = (new ReflectionMethod(Woob::class, 'buildCommand'))->invoke(null, 'history', '1@bnp');
        } finally {
            Config::set('woob_debug', false);
        }

        $this->assertStringContainsString("bank history '1@bnp'", $command);
        $this->assertStringContainsString('--auto-update', $command);
        $this->assertStringContainsString('--debug', $command);
        $this->assertStringContainsString('-f json', $command);
    }

    public function testBackendsFileMadePrivate()
    {
        // A file of the banks readable by everyone (copied into a NAS shared folder)
        $home = sys_get_temp_dir() . '/carbure-woob-' . uniqid();
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
}
