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

namespace Framework\Config\Support;

class Arr
{
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if ($key === '' || $key === null) {
            return $array;
        }
        $segments = explode('.', $key);
        $cursor = $array;
        foreach ($segments as $seg) {
            if (is_array($cursor) && array_key_exists($seg, $cursor)) {
                $cursor = $cursor[$seg];
            } else {
                return $default;
            }
        }
        return $cursor;
    }
}
