<?php

declare(strict_types=1);

use Framework\Config\Cache\ConfigCache;
use Framework\Config\Config;
use Framework\Config\ConfigService;
use Framework\Config\Exception\ConfigException;
use Framework\Config\Loader\LoaderInterface;

final class ConfigTest extends \PHPUnit\Framework\TestCase
{
    private string $fixturesDir;

    private string $workDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
        $this->workDir = sys_get_temp_dir() . '/cfgcache/' . uniqid('cfg_', true);
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testLoadFromDirectoryAndGetValues(): void
    {
        $config = $this->newConfig($this->fixturesDir);
        $all = $config->load();

        $this->assertIsArray($all);
        $this->assertEquals('MyApp', $config->get('app.name'));
        $this->assertEquals('127.0.0.1', $config->get('database.host'));
        $this->assertEquals('smtp.example.com', $config->get('mail.server'));
    }

    public function testDirectoryScanFindsPhpJsonAndIniWithoutBraceGlob(): void
    {
        $dir = $this->workDir . '/mixed';
        mkdir($dir);
        file_put_contents($dir . '/app.php', "<?php\nreturn ['name' => 'FromPhp'];\n");
        file_put_contents($dir . '/database.json', '{"host":"10.0.0.1"}');
        file_put_contents($dir . '/mail.ini', "server = smtp.local\n");
        file_put_contents($dir . '/readme.txt', 'ignored');

        $config = $this->newConfig($dir);
        $all = $config->load();

        $this->assertSame('FromPhp', $all['app']['name']);
        $this->assertSame('10.0.0.1', $all['database']['host']);
        $this->assertSame('smtp.local', $all['mail']['server']);
        $this->assertArrayNotHasKey('readme', $all);
    }

    public function testMissingConfigDirectoryThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config directory not found');

        $this->newConfig($this->workDir . '/does-not-exist');
    }

    public function testEmptyConfigDirectoryThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config directory cannot be empty');

        $this->newConfig('');
    }

    public function testRelativeConfigDirectoryIsResolvedAgainstCwd(): void
    {
        $dir = $this->workDir . '/rel';
        mkdir($dir);
        file_put_contents($dir . '/app.php', "<?php\nreturn ['name' => 'Relative'];\n");

        $originalCwd = getcwd();
        $this->assertNotFalse($originalCwd);

        try {
            chdir($this->workDir);
            $config = $this->newConfig('rel');
            chdir($originalCwd);
            $this->assertSame('Relative', $config->get('app.name'));
        } finally {
            chdir($originalCwd);
        }
    }

    public function testExplicitFileListMissingFileThrows(): void
    {
        $dir = $this->workDir . '/listed';
        mkdir($dir);
        file_put_contents($dir . '/app.php', "<?php\nreturn ['name' => 'Listed'];\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config file not found');

        $config = new Config($dir, $this->newCache(), ['app.php', 'missing.php']);
        $config->load();
    }

    public function testExplicitFileListLoadsOnlyNamedFiles(): void
    {
        $dir = $this->workDir . '/listed-ok';
        mkdir($dir);
        file_put_contents($dir . '/app.php', "<?php\nreturn ['name' => 'Listed'];\n");
        file_put_contents($dir . '/other.php', "<?php\nreturn ['name' => 'Other'];\n");

        $config = new Config($dir, $this->newCache(), ['app.php']);
        $all = $config->load();

        $this->assertSame('Listed', $all['app']['name']);
        $this->assertArrayNotHasKey('other', $all);
    }

    public function testExcludedFileIsReloadedOnNewInstance(): void
    {
        $dir = $this->workDir . '/excluded';
        mkdir($dir);
        file_put_contents($dir . '/app.php', "<?php\nreturn ['name' => 'App'];\n");
        file_put_contents($dir . '/mail.ini', "server = smtp.original\n");

        $cacheFile = $this->workDir . '/cache_excluded.php';
        $config = new Config($dir, new ConfigCache($cacheFile, 3600), null, ['mail.ini']);
        $config->load();
        $this->assertSame('smtp.original', $config->get('mail.server'));

        file_put_contents($dir . '/mail.ini', "server = smtp.changed\n");

        $next = new Config($dir, new ConfigCache($cacheFile, 3600), null, ['mail.ini']);
        $this->assertSame('smtp.changed', $next->get('mail.server'));
        $this->assertSame('App', $next->get('app.name'));
    }

    public function testMissingExcludedFileIsIgnored(): void
    {
        $dir = $this->workDir . '/no-excluded';
        mkdir($dir);
        file_put_contents($dir . '/app.php', "<?php\nreturn ['name' => 'App'];\n");

        $config = new Config($dir, $this->newCache(), null, ['routes.php', 'services.php']);
        $this->assertSame('App', $config->get('app.name'));
    }

    public function testConfigAliasExtendsConfigService(): void
    {
        $config = $this->newConfig($this->fixturesDir);

        $this->assertInstanceOf(ConfigService::class, $config);
    }

    public function testWindowsStyleAbsolutePathIsDetected(): void
    {
        $method = new \ReflectionMethod(ConfigService::class, 'isAbsolutePath');
        $method->setAccessible(true);
        $config = $this->newConfig($this->fixturesDir);

        $this->assertTrue($method->invoke($config, 'C:\\config\\app.php'));
        $this->assertTrue($method->invoke($config, 'D:/config/app.php'));
        $this->assertTrue($method->invoke($config, '/etc/app/config'));
        $this->assertTrue($method->invoke($config, '\\\\server\\share\\app.php'));
        $this->assertFalse($method->invoke($config, 'app.php'));
        $this->assertFalse($method->invoke($config, 'config/app.php'));
    }

    public function testCustomLoaderExtensionIsDiscoveredFromDirectory(): void
    {
        $dir = $this->workDir . '/yaml-ext';
        mkdir($dir);
        file_put_contents($dir . '/feature.yml', "name: yaml-config\n");

        $loader = new class implements LoaderInterface {
            public function load(string $filePath): array
            {
                $line = trim((string) file_get_contents($filePath));
                [, $value] = array_map('trim', explode(':', $line, 2));

                return ['name' => $value];
            }
        };

        $config = new Config($dir, $this->newCache(), null, [], ['yml' => $loader]);
        $this->assertSame('yaml-config', $config->get('feature.name'));
    }

    private function newConfig(string $dir): Config
    {
        return new Config($dir, $this->newCache());
    }

    private function newCache(): ConfigCache
    {
        return new ConfigCache($this->workDir . '/cache_' . uniqid('', true) . '.php', 3600);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
