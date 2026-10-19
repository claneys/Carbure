<?php

use Carbure\Lib\File;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    public function testRead()
    {
        $file = tempnam(carbure_test_tmp(), 'file');
        file_put_contents($file, "content\n");
        try {
            $this->assertSame("content\n", File::read($file));
        } finally {
            unlink($file);
        }
    }

    public function testAMissingFileThrows()
    {
        // Never false given as the content (an empty configuration written...)
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot read the file');
        File::read(carbure_test_tmp('missing-' . uniqid()));
    }
}
