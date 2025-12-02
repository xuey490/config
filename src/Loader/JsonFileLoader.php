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

class JsonFileLoader implements LoaderInterface
{
    public function load(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new ConfigException("JSON config file not found: {$filePath}");
        }
        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new ConfigException("Failed to read JSON file: {$filePath}");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ConfigException("Invalid JSON config file: {$filePath}");
        }
        return $data;
    }
}
