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
 * @Email: xuey863toy@gmail.com
 */

namespace Framework\Config;

use Framework\Config\Cache\ConfigCache;
use Framework\Config\Loader\LoaderInterface;
use Framework\Config\Loader\PhpFileLoader;
use Framework\Config\Loader\JsonFileLoader;
use Framework\Config\Loader\IniFileLoader;
use Framework\Config\Exception\ConfigException;
use Framework\Config\Support\Arr;

class ConfigService
{
    private array $config = [];
    private bool $loaded = false;

    /**
     * @param string $configDir Config 目录
     * @param ConfigCache $cache 注入缓存驱动
     * @param array|null $fileList 手动指定文件列表
     * @param array $excludedFiles 需要排除的文件名（如 ['routes.php']），需包含扩展名
     * @param array|null $customLoaders 自定义加载器
     */
    public function __construct(
        private string $configDir,
        private ConfigCache $cache,
        private ?array $fileList = null,
        private array $excludedFiles = ['routes.php', 'services.php'],
        private ?array $customLoaders = null
    ) {
        $this->configDir = rtrim($this->configDir, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * 加载配置（核心逻辑：静态缓存配置 + 动态排除文件配置）
     */
    public function load(): array
    {
        if ($this->loaded) {
            return $this->config;
        }

        $staticConfig = $this->getStaticConfig();
        $dynamicConfig = $this->loadExcludedConfigs();

        // 合并配置：动态配置覆盖静态配置
        $this->config = array_merge($staticConfig, $dynamicConfig);
        $this->loaded = true;

        return $this->config;
    }

    /**
     * 获取可缓存的静态配置（跳过排除文件）
     */
    private function getStaticConfig(): array
    {
        // 1. 收集所有有效配置文件（扫描目录/手动指定）
        $files = $this->fileList !== null
            ? $this->normalizeFileList($this->fileList)
            : $this->listConfigFilesFromDir();

        // 2. 过滤排除文件（只监控需要缓存的文件）
        $validFiles = [];
        foreach ($files as $file) {
            if (!$this->isExcluded($file)) {
                $validFiles[] = $file;
            }
        }

        // 将配置文件列表传给缓存（自动刷新的核心）
        $this->cache->setConfigFiles($validFiles);

        // 3. 原有缓存逻辑不变
        $cached = $this->cache->get();
        if (is_array($cached)) {
            return $cached;
        }

        $data = [];
        foreach ($validFiles as $file) {
            $key = $this->keyFromFile($file);
            $loader = $this->resolveLoaderForFile($file);
            $data[$key] = $loader->load($file);
        }

        $this->cache->set($data);
        return $data;
    }

    /**
     * 实时加载被排除的文件（修复：仅加载需要作为配置的排除文件，非配置文件跳过）
     */
    private function loadExcludedConfigs(): array
    {
        $data = [];
        foreach ($this->excludedFiles as $filename) {
            $filePath = $this->configDir . $filename;

            // 修复1：先判断文件是否存在
            if (!is_file($filePath)) {
                continue;
            }

            // 修复2：判断文件是否为合法的配置文件（避免加载非配置文件如路由文件）
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $supportedExts = ['php', 'json', 'ini'];
            if (!in_array($ext, $supportedExts, true)) {
                continue;
            }

            // 修复3：加载前先检查 PHP 文件是否返回数组（针对 PHP 类型的排除文件）
            if ($ext === 'php') {
                // 先尝试加载文件，验证是否返回数组
                $tempData = require $filePath;
                if (!is_array($tempData)) {
                    // 非数组返回的 PHP 文件，视为非配置文件，跳过加载
                    continue;
                }
                $data[$this->keyFromFile($filePath)] = $tempData;
            } else {
                // 其他格式文件正常加载
                $key = $this->keyFromFile($filePath);
                $loader = $this->resolveLoaderForFile($filePath);
                $data[$key] = $loader->load($filePath);
            }
        }
        return $data;
    }

    /**
     * 判断文件是否在排除列表中（修复：支持大小写不敏感匹配，避免路径问题）
     */
    private function isExcluded(string $filePath): bool
    {
        $filename = basename($filePath);
        // 修复：转为小写后匹配，避免大小写问题（如 Routes.php 和 routes.php 都能被排除）
        $lowerFilename = strtolower($filename);
        $lowerExcludedFiles = array_map('strtolower', $this->excludedFiles);
        return in_array($lowerFilename, $lowerExcludedFiles, true);
    }

    /**
     * 根据键名获取配置值
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->loaded) {
            $this->load();
        }
        return Arr::get($this->config, $key, $default);
    }

    /**
     * 清空缓存并重置配置
     */
    public function clearCache(): void
    {
        $this->cache->clear();
        $this->config = [];
        $this->loaded = false;
    }

    /**
     * 获取当前排除的文件列表
     */
    public function getExcludedFiles(): array
    {
        return $this->excludedFiles;
    }

    /**
     * 动态添加排除文件
     */
    public function addExcludedFile(string $filename): void
    {
        if (!in_array($filename, $this->excludedFiles, true)) {
            $this->excludedFiles[] = $filename;
            $this->clearCache();
        }
    }

    // --- 原有辅助方法保持不变 ---
    private function normalizeFileList(array $list): array
    {
        $out = [];
        foreach ($list as $f) {
            $path = $f;
            if (! (strpos($f, DIRECTORY_SEPARATOR) === 0 || preg_match('#^[A-Za-z]:\\\\#', $f))) {
                $path = $this->configDir . ltrim($f, '/\\');
            }
            if (is_file($path)) {
                $out[] = $path;
            }
        }
        return $out;
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

        if (is_array($this->customLoaders) && isset($this->customLoaders[$ext])) {
            $loader = $this->customLoaders[$ext];
            if (! $loader instanceof LoaderInterface) {
                throw new ConfigException("Custom loader for .$ext must implement LoaderInterface");
            }
            return $loader;
        }

        return match ($ext) {
            'php' => new PhpFileLoader(),
            'json' => new JsonFileLoader(),
            'ini' => new IniFileLoader(),
            default => throw new ConfigException("Unsupported config file extension: .$ext"),
        };
    }
}