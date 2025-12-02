<?php
declare(strict_types=1);

use Framework\Config\Config;
use Framework\Config\Cache\ConfigCache;

final class ConfigTest extends \PHPUnit\Framework\TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures/';
        if (!is_dir(sys_get_temp_dir() . '/cfgcache')) {
            mkdir(sys_get_temp_dir() . '/cfgcache', 0777, true);
        }
    }

    public function testLoadFromDirectoryAndGetValues(): void
    {
        $cacheFile = sys_get_temp_dir() . '/cfgcache/cache_dir.php';
        @unlink($cacheFile);
        $cache = new ConfigCache($cacheFile, 3600);

        $config = new Config($this->fixturesDir, $cache);
        $all = $config->load();

        $this->assertIsArray($all);
        $this->assertEquals('MyApp', $config->get('app.name'));
        $this->assertEquals('127.0.0.1', $config->get('database.host'));
        $this->assertEquals('smtp.example.com', $config->get('mail.server'));
    }

    public function testLoadFromFileListUsesCacheNextTime(): void
    {
        $cacheFile = sys_get_temp_dir() . '/cfgcache/cache_list.php';
        @unlink($cacheFile);
        $cache = new ConfigCache($cacheFile, 3600);

        $fileList = ['app.php', 'database.json', 'mail.ini'];
        $config = new Config($this->fixturesDir, $cache, ['mail.ini']); // mail.ini excluded

        // first load writes cache (without mail)
        $config->load();

        // modify mail.ini to a different value to ensure excluded file is reloaded each time
        file_put_contents($this->fixturesDir . 'mail.ini', "server = smtp.changed.example.com\n");

        // Next get should reflect changed mail.ini (excluded)
        $this->assertEquals('smtp.changed.example.com', $config->get('mail.server'));
    }
}
