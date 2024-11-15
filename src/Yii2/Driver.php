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

namespace Ripple\Driver\Yii2;

class Driver
{
    public const DECLARE_OPTIONS = [
        'HTTP_LISTEN'    => 'string',
        'HTTP_WORKERS'   => 'int',
        'HTTP_RELOAD'    => 'bool',
        'HTTP_SANDBOX'   => 'bool',
        'HTTP_ISOLATION' => 'bool',
    ];
}
