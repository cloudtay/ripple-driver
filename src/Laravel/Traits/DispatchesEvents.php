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

namespace Ripple\Driver\Laravel\Traits;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;

trait DispatchesEvents
{
    /**
     * Dispatch the given event via the given application.
     *
     * @param \Illuminate\Foundation\Application $app
     * @param mixed                              $event
     */
    public function dispatchEvent(Application $app, mixed $event): void
    {
        if ($app->bound(Dispatcher::class)) {
            $app[Dispatcher::class]->dispatch($event);
        }
    }
}
