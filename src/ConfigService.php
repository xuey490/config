<?php

declare(strict_types=1);

/**
 * This file is part of FssPHP Framework.
 *
 * @link     https://github.com/xuey490/project
 * @license  https://github.com/xuey490/project/blob/main/LICENSE
 *
 * @Filename: ConfigService.php
 * @Date: 2025-12-3
 * @Developer: xuey863toy
 */

namespace Framework\Config;

use Framework\Config\Cache\ConfigCache;
use Framework\Config\Loader\LoaderInterface;
use Framework\Config\Loader\PhpFileLoader;
use Framework\Config\Loader\JsonFileLoader;
use Framework\Config\Loader\IniFileLoader;
use Framework\Config\Exception\ConfigException;
use Framework\Config\Support\Arr;

/**
 * 配置中心：负责扫描、加载、缓存配置
 * 支持排除指定文件（动态文件）并跳过缓存
 */
class ConfigService
{
    /** @var array 已加载的配置内容 */
    private array $config = [];

    /** @var bool 是否已经加载过 */
    private bool $loaded = false;

    /**
     * @param string       $configDir      配置目录
     * @param ConfigCache  $cache          缓存驱动
     * @param array|null   $fileList       手动指定文件列表
     * @param array        $excludedFiles  排除文件（动态加载，不缓存）
     * @param array|null   $customLoaders  自定义加载器
     */
    public function __construct(
        private string $configDir,
        private ConfigCache $cache,
        private ?array $fileList = null,
        private array $excludedFiles = ['routes.php', 'services.php'],
        private ?array $customLoaders = null
    ) {
        $this->configDir = rtrim($configDir, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * 加载配置：静态缓存 + 动态排除文件
     */
    public function load(): array
    {
        if ($this->loaded) {
            return $this->config;
        }

        $static = $this->loadStaticConfigs();
        $dynamic = $this->loadExcludedConfigs();

        // 动态配置覆盖静态
        $this->config = array_replace($static, $dynamic);
        $this->loaded = true;

        return $this->config;
    }

    /**
     * 加载可缓存配置（非排除文件）
     */
    private function loadStaticConfigs(): array
    {
        // 1. 获取配置文件列表
        $files = $this->fileList
            ? $this->normalizeFileList($this->fileList)
            : $this->listConfigFilesFromDir();

        // 2. 过滤排除文件
        $files = array_filter($files, fn($f) => !$this->isExcluded($f));

        // 注册给缓存：用于自动刷新判断
        $this->cache->setConfigFiles(array_values($files));

        // 3. 读取缓存
        $cached = $this->cache->get();
        if (is_array($cached)) {
            return $cached;
        }

        // 4. 未命中缓存 → 重新加载
        $result = [];
        foreach ($files as $file) {
            $key = $this->keyFromFile($file);
            $loader = $this->resolveLoaderForFile($file);
            $result[$key] = $loader->load($file);
        }

        $this->cache->set($result);
        return $result;
    }

    /**
     * 加载排除的动态文件（不缓存）
     */
    private function loadExcludedConfigs(): array
    {
        $result = [];

        foreach ($this->excludedFiles as $fileName) {
            $file = $this->configDir . $fileName;

            if (!is_file($file)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            // 支持的格式
            if (!in_array($ext, ['php', 'json', 'ini'], true)) {
                continue;
            }

            $key = $this->keyFromFile($file);

            // PHP 文件必须返回数组才视为配置
            if ($ext === 'php') {
                $value = require $file;
                if (is_array($value)) {
                    $result[$key] = $value;
                }
                continue;
            }

            // 其他格式正常加载
            $loader = $this->resolveLoaderForFile($file);
            $result[$key] = $loader->load($file);
        }

        return $result;
    }

    /**
     * 判断文件是否是排除文件（大小写不敏感）
     */
    private function isExcluded(string $filePath): bool
    {
        $filename = strtolower(basename($filePath));
        $excluded = array_map('strtolower', $this->excludedFiles);
        return in_array($filename, $excluded, true);
    }

    /**
     * 根据 key 获取配置值
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->load(), $key, $default);
    }

    /**
     * 清空缓存
     */
    public function clearCache(): void
    {
        $this->cache->clear();
        $this->config = [];
        $this->loaded = false;
    }

    public function getExcludedFiles(): array
    {
        return $this->excludedFiles;
    }

    public function addExcludedFile(string $file): void
    {
        if (!in_array($file, $this->excludedFiles, true)) {
            $this->excludedFiles[] = $file;
            $this->clearCache();
        }
    }

    // --- 辅助方法 -----------------------------------------------------

    private function normalizeFileList(array $list): array
    {
        $output = [];
        foreach ($list as $file) {
            // 不是绝对路径 → 转换为绝对路径
            if (!str_starts_with($file, DIRECTORY_SEPARATOR)
                && !preg_match('#^[A-Za-z]:\\\\#', $file)) {
                $file = $this->configDir . ltrim($file, '/\\');
            }
            if (is_file($file)) {
                $output[] = $file;
            }
        }
        return $output;
    }

    private function listConfigFilesFromDir(): array
    {
        $pattern = $this->configDir . '*.{php,json,ini}';
        return glob($pattern, GLOB_BRACE) ?: [];
    }

    private function keyFromFile(string $file): string
    {
        return pathinfo($file, PATHINFO_FILENAME);
    }

    private function resolveLoaderForFile(string $file): LoaderInterface
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($this->customLoaders[$ext] ?? false) {
            $loader = $this->customLoaders[$ext];
            if (!$loader instanceof LoaderInterface) {
                throw new ConfigException("Custom loader for .$ext must implement LoaderInterface");
            }
            return $loader;
        }

        return match ($ext) {
            'php'  => new PhpFileLoader(),
            'json' => new JsonFileLoader(),
            'ini'  => new IniFileLoader(),
            default => throw new ConfigException("Unsupported config extension: .$ext"),
        };
    }
}
