<?php

declare(strict_types=1);

/**
 * This file is part of FssPHP Framework.
 *
 * @link     https://github.com/xuey490/project
 * @license  https://github.com/xuey490/project/blob/main/LICENSE
 *
 * @Filename: %filename%
 * @Date: 2025-12-2
 * @Developer: xuey863toy
 * @Email: xuey863toy@gmail.com
 */

namespace Framework\Config;

use Framework\Config\Cache\ConfigCache;
use Framework\Config\Exception\ConfigException;
use Framework\Config\Loader\PhpFileLoader;
use Framework\Config\Loader\JsonFileLoader;
use Framework\Config\Loader\IniFileLoader;

final class Config
{
    private string $configDir;
	
    private ConfigCache $cache;

    /** @var array */
    private array $config = [];

    /** @var array */
    private array $excludeFiles = [];

    /** @var array */
    private array $loaders = [];

    public function __construct(
        string $configDir,
        ConfigCache $cache,
        array $excludeFiles = []
    ) {
        $this->configDir = rtrim($configDir, DIRECTORY_SEPARATOR);
        $this->cache = $cache;
        $this->excludeFiles = $excludeFiles;

        $this->loaders = [
            'php'  => new PhpFileLoader(),
            'json' => new JsonFileLoader(),
            'ini'  => new IniFileLoader(),
        ];
    }

    public function load(): mixed
    {
        if ($this->cache->isFresh()) {
            $this->config = $this->cache->load();
            return $this->config;
        }

        $files = glob($this->configDir . '/*.*');

        foreach ($files as $file) {
            $basename =  basename($file);

            // 排除配置文件
            if (in_array($basename, $this->excludeFiles, true)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (!isset($this->loaders[$ext])) {
                continue;
            }

            $data = $this->loaders[$ext]->load($file);

            if (!is_array($data)) {
                throw new ConfigException("Config file must return array: $file");
            }
			
			$key = pathinfo($file, PATHINFO_FILENAME); 
			
            $this->config[$key] = $data;
        }
		
        $this->cache->write($this->config);
		return $this->config;
    }

	public function all(): array
	{
		return $this->config;
	}

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $data = $this->config;

        foreach ($segments as $seg) {
            if (!isset($data[$seg])) {
                return $default;
            }
            $data = $data[$seg];
        }
		


        return $data;
    }
}
