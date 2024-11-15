<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Driver\Utils;

use function in_array;
use function is_string;
use function strtolower;

class Config
{
    /**
     * @param mixed  $value
     * @param string $type
     *
     * @return string
     */
    public static function value2string(mixed $value, string $type): string
    {
        return match ($type) {
            'bool'  => Config::value2bool($value) ? 'on' : 'off',
            default => (string)$value,
        };
    }

    /**
     * @param mixed $value
     *
     * @return bool
     */
    public static function value2bool(mixed $value): bool
    {
        if (is_string($value)) {
            $value = strtolower($value);
        }
        return in_array($value, ['on', 'true', 'yes', '1', 1, true], true);
    }
}
