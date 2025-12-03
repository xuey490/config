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
 
namespace Framework\Config\Cache;

final class ConfigCache
{
    public function __construct(
        private string $cacheFile,
        private int $ttl = 60
    ) {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    /**
     * 检查缓存是否存在且未过期
     */
    public function isFresh(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $mtime = filemtime($this->cacheFile);
        if ($mtime === false) {
            return false;
        }

        return (time() - $mtime) <= $this->ttl;
    }

    /**
     * 加载缓存内容
     */
    public function load(): array
    {
        $data = include $this->cacheFile;
        return is_array($data) ? $data : [];
    }

    /**
     * 写入缓存
     */
    public function write(array $data): void
    {
        $export = var_export($data, true);
        $content = <<<PHP
<?php
// generated at: {$this->now()}
return {$export};
PHP;

        @file_put_contents($this->cacheFile, $content);
    }

    /**
     * 兼容旧方法 set()
     */
    public function set(array $data): void
    {
        $this->write($data);
    }

    /**
     * 兼容旧方法 get()
     */
    public function get(): ?array
    {
        return $this->isFresh() ? $this->load() : null;
    }

    public function clear(): void
    {
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
