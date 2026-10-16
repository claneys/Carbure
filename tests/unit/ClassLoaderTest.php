<?php

use Carbure\Lib\ApiResolver;
use Carbure\Lib\ClassLoader;
use PHPUnit\Framework\TestCase;

class ClassLoaderTest extends TestCase
{
    public function testFileOfAClassOfCarbure()
    {
        $src = dirname(__DIR__, 2) . '/src';

        $this->assertSame(realpath("$src/lib/Config.php"), realpath(ClassLoader::fileOf('Carbure\Lib\Config')));
        $this->assertSame(realpath("$src/resources/User.php"), realpath(ClassLoader::fileOf('Carbure\Resources\User')));
        // A sub-namespace: its directory in lowercase
        $this->assertSame(realpath("$src/lib/import/CsvReader.php"), realpath(ClassLoader::fileOf('Carbure\Lib\Import\CsvReader')));
    }

    public function testOtherClassesAreNotLoaded()
    {
        // Another vendor, a global class, a lowercase sub-namespace, a path in the name
        $this->assertNull(ClassLoader::fileOf('PHPUnit\Framework\TestCase'));
        $this->assertNull(ClassLoader::fileOf('Config'));
        $this->assertNull(ClassLoader::fileOf('Carbure\Lib\import\CsvReader'));
        $this->assertNull(ClassLoader::fileOf('Carbure\Lib\..\resources\User'));
        $this->assertNull(ClassLoader::fileOf('Carbure\Lib\Import\banks\n26'));
        $this->assertNull(ClassLoader::fileOf('Carbure\Lib\\'));

        // A missing class of Carbure: nothing loaded, no error
        ClassLoader::load('Carbure\Lib\DoesNotExist');
        $this->assertFalse(class_exists('Carbure\Lib\DoesNotExist'));
    }

    public function testRegisterIsIdempotent()
    {
        $count = count(spl_autoload_functions());
        ClassLoader::register();
        $this->assertCount($count, spl_autoload_functions());
    }

    /**
     * Each file declares the class named after it, in the namespace of its directory
     * (the file systems of the servers are case-sensitive)
     */
    public function testEachFileDeclaresItsClass()
    {
        $src = dirname(__DIR__, 2) . '/src';
        $namespaces = [
            'lib' => 'Carbure\Lib\\',
            'lib/import' => 'Carbure\Lib\Import\\',
            'resources' => ApiResolver::RESOURCES_NAMESPACE,
        ];
        foreach ($namespaces as $dir => $namespace) {
            foreach (glob("$src/$dir/*.php") as $file) {
                $class = $namespace . basename($file, '.php');
                $this->assertTrue(class_exists($class), "$class is loaded from $file");
                $reflection = new ReflectionClass($class);
                $this->assertSame($class, $reflection->getName());
                $this->assertSame(realpath($file), realpath($reflection->getFileName()));
            }
        }
    }
}
