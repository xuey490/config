<?php

declare(strict_types=1);

/**
 * This file is part of FssPHP Framework.
 *
 * @link     https://github.com/xuey490/project
 * @license  https://github.com/xuey490/project/blob/main/LICENSE
 *
 * @Filename: ConfigCache.php
 * @Date: 2025-12-4
 * @Developer: xuey863toy
 * @Email: xuey863toy@gmail.com
 */
 
namespace Framework\Config\Cache;

use Framework\Config\Exception\ConfigException;

class ConfigCache
{
    private string $cacheFile;
    private int $ttl;
    private array $configFiles = []; // 配置文件列表（自动收集，无需手动传）

    /**
     * 完全兼容原有构造函数！无需额外传参
     * @param string $cacheFile 缓存文件路径
     * @param int $ttl 有效期（秒）
     * @param array $configFiles 配置文件列表（可选，自动收集）
     */
    public function __construct(string $cacheFile, int $ttl = 3600, array $configFiles = [])
    {
        $this->validateCachePath($cacheFile);
        $this->cacheFile = $cacheFile;
        $this->ttl = $ttl;
        $this->configFiles = $this->filterValidConfigFiles($configFiles);
    }

    /**
     * 兼容原有 get() 方法：返回配置数组/NULL
     */
    public function get(): ?array
    {
        // 若未设置配置文件列表（首次调用），直接返回原有逻辑（不影响兼容）
        if (empty($this->configFiles)) {
            return $this->getOriginalCache();
        }

        // 配置文件列表已设置，启用自动刷新逻辑
        if ($this->hasConfigFilesChanged()) {
            $this->clear();
            return null;
        }

        if (!file_exists($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }

        if (!$this->isCacheValid()) {
            $this->clear();
            return null;
        }

        $cacheContent = file_get_contents($this->cacheFile);
        if ($cacheContent === false) {
            $this->clear();
            return null;
        }

        $cachedData = @unserialize($cacheContent);
        if (!is_array($cachedData) || !isset($cachedData['config']) || !isset($cachedData['file_signature'])) {
            $this->clear();
            return null;
        }

        return $cachedData['config'];
    }

    /**
     * 兼容原有 set() 方法：传入配置数组即可
     */
    public function set(array $data): void
    {
        // 若未设置配置文件列表，使用原有序列化逻辑（兼容旧缓存）
        if (empty($this->configFiles)) {
            $this->setOriginalCache($data);
            return;
        }

        // 启用自动刷新逻辑：记录文件特征值
        $fileSignature = $this->generateFileSignature();
        $cacheData = [
            'file_signature' => $fileSignature,
            'config' => $data
        ];

        $serializedData = serialize($cacheData);
        if ($serializedData === false) {
            throw new ConfigException('Failed to serialize config data for caching');
        }

        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            throw new ConfigException("Failed to create cache directory: {$cacheDir}");
        }

        $fileHandle = fopen($this->cacheFile, 'w');
        if (!$fileHandle) {
            throw new ConfigException("Failed to open cache file for writing: {$this->cacheFile}");
        }

        if (!flock($fileHandle, LOCK_EX)) {
            fclose($fileHandle);
            throw new ConfigException("Failed to lock cache file: {$this->cacheFile}");
        }

        $writeResult = fwrite($fileHandle, $serializedData);
        fflush($fileHandle);
        flock($fileHandle, LOCK_UN);
        fclose($fileHandle);

        if ($writeResult === false || $writeResult !== strlen($serializedData)) {
            $this->clear();
            throw new ConfigException("Failed to write complete data to cache file: {$this->cacheFile}");
        }

        chmod($this->cacheFile, 0644);
    }

    /**
     * 兼容原有 clear() 方法
     */
    public function clear(): bool
    {
        if (file_exists($this->cacheFile)) {
            return unlink($this->cacheFile);
        }
        return true;
    }

    /**
     * 新增：设置配置文件列表（由 Config 类自动调用，你无需关心）
     */
    public function setConfigFiles(array $configFiles): void
    {
        $this->configFiles = $this->filterValidConfigFiles($configFiles);
    }

    // --- 原有 getter 方法（兼容用）---
    public function getCacheFile(): string
    {
        return $this->cacheFile;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    // --- 自动刷新核心逻辑（内部使用）---
    private function hasConfigFilesChanged(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $oldCacheContent = @file_get_contents($this->cacheFile);
        $oldCacheData = @unserialize($oldCacheContent);
        if (!is_array($oldCacheData) || !isset($oldCacheData['file_signature'])) {
            return true;
        }

        $currentSignature = $this->generateFileSignature();
        return $currentSignature !== $oldCacheData['file_signature'];
    }

    private function generateFileSignature(): string
    {
        $signatureParts = [];
        foreach ($this->configFiles as $file) {
            if (file_exists($file) && is_readable($file)) {
                $mtime = (string)filemtime($file);
                $size = (string)filesize($file);
                $signatureParts[] = $file . '|' . $mtime . '|' . $size;
            } else {
                $signatureParts[] = $file . '|0|0';
            }
        }
        return md5(implode(';', $signatureParts));
    }

    // --- 原有缓存逻辑（兼容旧用法）---
    private function getOriginalCache(): ?array
    {
        if (!file_exists($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }

        if (!$this->isCacheValid()) {
            $this->clear();
            return null;
        }

        $cacheContent = file_get_contents($this->cacheFile);
        if ($cacheContent === false) {
            $this->clear();
            return null;
        }

        $cachedData = @unserialize($cacheContent);
        return is_array($cachedData) ? $cachedData : null;
    }

    private function setOriginalCache(array $data): void
    {
        $serializedData = serialize($data);
        if ($serializedData === false) {
            throw new ConfigException('Failed to serialize config data for caching');
        }

        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            throw new ConfigException("Failed to create cache directory: {$cacheDir}");
        }

        $fileHandle = fopen($this->cacheFile, 'w');
        if (!$fileHandle) {
            throw new ConfigException("Failed to open cache file for writing: {$this->cacheFile}");
        }

        if (!flock($fileHandle, LOCK_EX)) {
            fclose($fileHandle);
            throw new ConfigException("Failed to lock cache file: {$this->cacheFile}");
        }

        $writeResult = fwrite($fileHandle, $serializedData);
        fflush($fileHandle);
        flock($fileHandle, LOCK_UN);
        fclose($fileHandle);

        if ($writeResult === false || $writeResult !== strlen($serializedData)) {
            $this->clear();
            throw new ConfigException("Failed to write complete data to cache file: {$this->cacheFile}");
        }

        chmod($this->cacheFile, 0644);
    }

    // --- 辅助方法 ---
    private function isCacheValid(): bool
    {
        if ($this->ttl <= 0) {
            return true;
        }

        $fileMtime = filemtime($this->cacheFile);
        if ($fileMtime === false) {
            return false;
        }

        return (time() - $fileMtime) <= $this->ttl;
    }

    private function filterValidConfigFiles(array $files): array
    {
        $validFiles = [];
        foreach ($files as $file) {
            $file = realpath($file);
            if ($file && is_file($file) && is_readable($file)) {
                $validFiles[] = $file;
            }
        }
        return array_unique($validFiles);
    }

    private function validateCachePath(string $cacheFile): void
    {
        $cacheDir = dirname($cacheFile);
        $systemTempDir = sys_get_temp_dir();
        if (strpos(realpath($cacheDir) ?: $cacheDir, realpath($systemTempDir) ?: $systemTempDir) === 0) {
            throw new ConfigException('Cache file cannot be placed in system temporary directory');
        }

        $webRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!empty($webRoot) && strpos(realpath($cacheFile) ?: $cacheFile, realpath($webRoot) ?: $webRoot) === 0) {
            throw new ConfigException('Cache file cannot be placed in web-accessible directory for security reasons');
        }
    }
}