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

use Illuminate\Support\Env;

return [
    'HTTP_LISTEN'  => Env::get('RIP_HTTP_LISTEN', 'http://127.0.0.1:8008'),
    'HTTP_WORKERS' => Env::get('RIP_HTTP_WORKERS', 4),
    'HTTP_RELOAD'  => Env::get('RIP_HTTP_RELOAD', 0)
];
