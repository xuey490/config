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

use Framework\Config\Exception\ConfigException;

class PhpFileLoader implements LoaderInterface
{
    public function load(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new ConfigException("PHP config file not found: {$filePath}");
        }

        $data = require $filePath;
        if (!is_array($data)) {
            throw new ConfigException("PHP config file must return an array: {$filePath}");
        }

        return $data;
    }
}
