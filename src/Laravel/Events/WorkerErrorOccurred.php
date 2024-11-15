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

namespace Ripple\Driver\Laravel\Events;

use Illuminate\Foundation\Application;
use Ripple\Driver\Laravel\Coroutine\ContainerMap;
use Throwable;

class WorkerErrorOccurred
{
    /**
     * @param \Illuminate\Foundation\Application $app
     * @param \Illuminate\Foundation\Application $sandbox
     * @param Throwable                         $exception
     */
    public function __construct(public Application $app, public Application $sandbox, public Throwable $exception)
    {
        ContainerMap::unbind();
    }
}
