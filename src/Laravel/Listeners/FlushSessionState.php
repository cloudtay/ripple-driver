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

class FlushSessionState
{
    /**
     * Handle the event.
     *
     * @param mixed $event
     */
    public function handle(mixed $event): void
    {
        if (!$event->sandbox->resolved('session')) {
            return;
        }

        $driver = $event->sandbox->make('session')->driver();

        $driver->flush();
        $driver->regenerate();
    }
}
