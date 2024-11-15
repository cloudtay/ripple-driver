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

namespace Ripple\Driver\Laravel\Listeners;

use function with;

class FlushAuthenticationState
{
    /**
     * Handle the event.
     *
     * @param mixed $event
     */
    public function handle(mixed $event): void
    {
        if ($event->sandbox->resolved('auth.driver')) {
            $event->sandbox->forgetInstance('auth.driver');
        }

        if ($event->sandbox->resolved('auth')) {
            with($event->sandbox->make('auth'), function ($auth) use ($event) {
                $auth->setApplication($event->sandbox);
                $auth->forgetGuards();
            });
        }
    }
}
