<?php

/**
 * File.php
 *
 * Reading of the files of Carbure (SQL scripts, configuration, setup code...).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Exception;

final class File
{
    /**
     * Content of a file, like file_get_contents() but never false.
     *
     * @param string $path The path of the file
     * @return string The content
     * @throws Exception If the file cannot be read
     */
    public static function read(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new Exception("Cannot read the file $path");
        }
        return $content;
    }
}
