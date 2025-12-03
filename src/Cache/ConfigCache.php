<?php

declare(strict_types=1);

/**
 * This file is part of FssPHP Framework.
 *
 * @link     https://github.com/xuey490/project
 * @license  https://github.com/xuey490/project/blob/main/LICENSE
 *
 * @Filename: ConfigCache.php
 * @Date: 2025-12-3
 * @Developer: xuey863toy
 * @Email: xuey863toy@gmail.com
 */

namespace Framework\Config\Cache;

use Framework\Config\Exception\ConfigException;

class ConfigCache
{
    /**
     * 缓存文件路径
     */
    private string $cacheFile;

    /**
     * 缓存有效期（秒）
     */
    private int $ttl;

    /**
     * 构造函数
     * @param string $cacheFile 缓存文件完整路径
     * @param int $ttl 缓存有效期（秒），0 表示永久有效
     * @throws ConfigException 缓存文件路径不合法时抛出异常
     */
    public function __construct(string $cacheFile, int $ttl = 3600)
    {
        // 校验缓存文件路径合法性
        $this->validateCachePath($cacheFile);
        
        $this->cacheFile = $cacheFile;
        $this->ttl = $ttl;
    }

    /**
     * 获取缓存（带完整有效性校验）
     * @return array|null 有效缓存数组，无效则返回 null
     */
    public function get(): ?array
    {
        // 1. 校验缓存文件是否存在
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        // 2. 校验缓存文件是否可读取
        if (!is_readable($this->cacheFile)) {
            $this->clear(); // 清除不可读的无效缓存
            return null;
        }

        // 3. 校验缓存是否过期
        if (!$this->isCacheValid()) {
            $this->clear(); // 清除过期缓存
            return null;
        }

        // 4. 读取并校验缓存内容格式
        $cacheContent = file_get_contents($this->cacheFile);
        if ($cacheContent === false) {
            $this->clear(); // 读取失败，清除无效缓存
            return null;
        }

        // 反序列化缓存（使用 unserialize 而非 eval，更安全）
        $cachedData = @unserialize($cacheContent); // @ 抑制反序列化警告，后续手动判断

        // 5. 校验缓存内容是否为合法数组
        if (!is_array($cachedData)) {
            $this->clear(); // 格式错误，清除无效缓存
            return null;
        }

        return $cachedData;
    }

    /**
     * 写入缓存（确保写入成功）
     * @param array $data 要缓存的配置数组
     * @throws ConfigException 缓存写入失败时抛出异常
     */
    public function set(array $data): void
    {
        // 序列化数据（保留数组结构，比 var_export 更高效且安全）
        $serializedData = serialize($data);
        if ($serializedData === false) {
            throw new ConfigException('Failed to serialize config data for caching');
        }

        // 确保缓存目录存在（避免目录不存在导致写入失败）
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            throw new ConfigException("Failed to create cache directory: {$cacheDir}");
        }

        // 写入缓存文件（加锁避免并发写入冲突）
        $fileHandle = fopen($this->cacheFile, 'w');
        if (!$fileHandle) {
            throw new ConfigException("Failed to open cache file for writing: {$this->cacheFile}");
        }

        // 排他锁：确保同一时间只有一个进程写入
        if (!flock($fileHandle, LOCK_EX)) {
            fclose($fileHandle);
            throw new ConfigException("Failed to lock cache file: {$this->cacheFile}");
        }

        // 写入内容并强制刷新到磁盘（避免系统缓存导致数据丢失）
        $writeResult = fwrite($fileHandle, $serializedData);
        fflush($fileHandle);
        flock($fileHandle, LOCK_UN); // 释放锁
        fclose($fileHandle);

        if ($writeResult === false || $writeResult !== strlen($serializedData)) {
            $this->clear(); // 写入不完整，清除无效缓存
            throw new ConfigException("Failed to write complete data to cache file: {$this->cacheFile}");
        }

        // 设置文件权限（避免权限过高导致安全问题）
        chmod($this->cacheFile, 0644);
    }

    /**
     * 清除缓存
     * @return bool 清除成功返回 true，失败返回 false
     */
    public function clear(): bool
    {
        if (file_exists($this->cacheFile)) {
            return unlink($this->cacheFile);
        }
        return true; // 文件不存在视为清除成功
    }

    /**
     * 校验缓存是否在有效期内
     * @return bool 有效返回 true，无效返回 false
     */
    private function isCacheValid(): bool
    {
        // TTL 为 0 表示永久有效
        if ($this->ttl <= 0) {
            return true;
        }

        // 获取文件修改时间（缓存生成时间）
        $fileMtime = filemtime($this->cacheFile);
        if ($fileMtime === false) {
            return false; // 获取修改时间失败，视为无效
        }

        // 计算缓存是否过期（当前时间 - 缓存生成时间 > TTL 则过期）
        return (time() - $fileMtime) <= $this->ttl;
    }

    /**
     * 校验缓存文件路径合法性
     * @param string $cacheFile 缓存文件路径
     * @throws ConfigException 路径不合法时抛出异常
     */
    private function validateCachePath(string $cacheFile): void
    {
        $cacheDir = dirname($cacheFile);
        
        // 禁止使用系统临时目录（避免缓存被系统自动清理）
        $systemTempDir = sys_get_temp_dir();
        if (strpos(realpath($cacheDir) ?: $cacheDir, realpath($systemTempDir) ?: $systemTempDir) === 0) {
            throw new ConfigException('Cache file cannot be placed in system temporary directory');
        }

        // 禁止使用 web 可访问目录（避免缓存文件被直接下载，泄露配置）
        $webRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!empty($webRoot) && strpos(realpath($cacheFile) ?: $cacheFile, realpath($webRoot) ?: $webRoot) === 0) {
            throw new ConfigException('Cache file cannot be placed in web-accessible directory for security reasons');
        }
    }
}