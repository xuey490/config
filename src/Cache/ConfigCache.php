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

namespace Framework\Config\Cache;

use Framework\Config\Exception\ConfigException;

/**
 * 配置缓存：支持自动刷新机制（基于文件签名）
 */
class ConfigCache
{
    /** @var string 缓存文件 */
    private string $cacheFile;

    /** @var int 缓存有效期（秒） */
    private int $ttl;

    /** @var array 当前参与签名的配置文件 */
    private array $configFiles = [];

    public function __construct(string $cacheFile, int $ttl = 3600, array $configFiles = [])
    {
        $this->validateCachePath($cacheFile);
        $this->cacheFile = $cacheFile;
        $this->ttl = $ttl;
        $this->configFiles = $this->filterValidConfigFiles($configFiles);
    }

    /**
     * 获取缓存内容（支持自动刷新）
     */
    public function get(): ?array
    {
        // 未设置 configFiles → 使用旧逻辑（兼容）
        if (empty($this->configFiles)) {
            return $this->getOriginalCache();
        }

        // 自动刷新机制：文件变动则清缓存
        if ($this->hasConfigFilesChanged()) {
            $this->clear();
            return null;
        }

        if (!file_exists($this->cacheFile)) {
            return null;
        }

        if (!$this->isCacheValid()) {
            $this->clear();
            return null;
        }

        $data = @unserialize((string)file_get_contents($this->cacheFile));

        if (!is_array($data) || !isset($data['config'], $data['file_signature'])) {
            $this->clear();
            return null;
        }

        return $data['config'];
    }

    /**
     * 保存缓存（自动刷新模式）
     */
    public function set(array $data): void
    {
        // 无 configFiles → 使用兼容旧逻辑
        if (empty($this->configFiles)) {
            $this->setOriginalCache($data);
            return;
        }

        $payload = [
            'file_signature' => $this->generateFileSignature(),
            'config'         => $data,
        ];

        $this->writeCacheData($payload);
    }

    /**
     * 删除缓存文件
     */
    public function clear(): bool
    {
        return !file_exists($this->cacheFile) || unlink($this->cacheFile);
    }

    /**
     * 设置配置文件列表（动态由 ConfigService 调用）
     */
    public function setConfigFiles(array $files): void
    {
        $this->configFiles = $this->filterValidConfigFiles($files);
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    // ---------------------------------------------------------------------
    // 自动刷新：文件签名逻辑
    // ---------------------------------------------------------------------

    private function hasConfigFilesChanged(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $old = @unserialize((string)file_get_contents($this->cacheFile));
        if (!is_array($old) || !isset($old['file_signature'])) {
            return true;
        }

        return $old['file_signature'] !== $this->generateFileSignature();
    }

    private function generateFileSignature(): string
    {
        $parts = [];
        foreach ($this->configFiles as $file) {
            if (is_file($file)) {
                $parts[] = "{$file}|" . filemtime($file) . "|" . filesize($file);
            } else {
                $parts[] = "{$file}|0|0";
            }
        }

        return md5(implode(';', $parts));
    }

    // ---------------------------------------------------------------------
    // 兼容旧逻辑：原始缓存模式
    // ---------------------------------------------------------------------

    private function getOriginalCache(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        if (!$this->isCacheValid()) {
            $this->clear();
            return null;
        }

        $data = @unserialize((string)file_get_contents($this->cacheFile));
        return is_array($data) ? $data : null;
    }

    private function setOriginalCache(array $data): void
    {
        $this->writeCacheData($data);
    }

    // ---------------------------------------------------------------------
    // 通用写入逻辑（原始模式 & 自动刷新都有）
    // ---------------------------------------------------------------------

    private function writeCacheData(array $data): void
    {
        $payload = serialize($data);
        if ($payload === false) {
            throw new ConfigException('Failed to serialize config data');
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new ConfigException("Failed to create cache directory: {$dir}");
        }

        $fp = fopen($this->cacheFile, 'w');
        if (!$fp) {
            throw new ConfigException("Failed to write cache file: {$this->cacheFile}");
        }

        flock($fp, LOCK_EX);
        fwrite($fp, $payload);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        chmod($this->cacheFile, 0644);
    }

    // ---------------------------------------------------------------------
    // 辅助
    // ---------------------------------------------------------------------

    private function validateCachePath(string $path): void
    {
        if ($path === '') {
            throw new ConfigException("Cache file path cannot be empty");
        }
    }

    private function filterValidConfigFiles(array $list): array
    {
        return array_values(array_filter(
            $list,
            fn($f) => is_string($f) && $f !== ''
        ));
    }

    private function isCacheValid(): bool
    {
        return file_exists($this->cacheFile)
            && (filemtime($this->cacheFile) + $this->ttl) >= time();
    }
}
