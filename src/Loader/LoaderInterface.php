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

namespace Framework\Config\Loader;

interface LoaderInterface
{
    /**
     * @param string $filePath
     * @return array 返回配置数组（若文件内容无效应返回空数组）
     */
    public function load(string $filePath): array;
}
