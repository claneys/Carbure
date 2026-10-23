<?php

use Carbure\Lib\Config;
use Carbure\Lib\ReleaseCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comparison of the versions and reading of the latest release of GitHub (ReleaseCheck).
 */
class ReleaseCheckTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}> current, latest, newer
     */
    public static function versions(): array
    {
        return [
            'patch' => ['1.9.1', '1.9.2', true],
            'minor, compared as numbers' => ['1.9.1', '1.10.0', true],
            'major' => ['1.10.0', '2.0.0', true],
            'same' => ['1.10.0', '1.10.0', false],
            'older' => ['1.10.0', '1.9.9', false],
            'development version' => ['dev', '9.9.9', false],
            'no release known' => ['1.9.1', '', false],
            'not a version' => ['1.9.1', '2.0', false],
        ];
    }

    #[DataProvider('versions')]
    public function testIsNewer(string $current, string $latest, bool $newer)
    {
        $this->assertSame($newer, ReleaseCheck::isNewer($current, $latest));
    }

    public function testParseTheLatestRelease()
    {
        $this->assertSame(['version' => '1.10.0', 'url' => 'https://github.com/ltoinel/Carbure/releases/tag/v1.10.0'],
            ReleaseCheck::parse(['tag_name' => 'v1.10.0', 'html_url' => 'https://evil.example/']));
        // Anything else than a vX.Y.Z release is ignored
        foreach ([null, 'v1.10.0', [], ['tag_name' => '1.10.0'], ['tag_name' => 'v1.10'], ['tag_name' => 'v1.10.0-rc1'], ['tag_name' => ['v1']]] as $answer) {
            $this->assertNull(ReleaseCheck::parse($answer), json_encode($answer));
        }
    }

    public function testOnUnlessTurnedOff()
    {
        try {
            Config::set('update_check', '');
            $this->assertFalse(ReleaseCheck::enabled());
            Config::set('update_check', '1');
            $this->assertTrue(ReleaseCheck::enabled());
        } finally {
            Config::set('update_check', true);
        }
    }
}
