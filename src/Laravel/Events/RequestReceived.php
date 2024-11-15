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

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Ripple\Driver\Laravel\Coroutine\ContainerMap;
use Ripple\Driver\Laravel\Listeners\FlushAuthenticationState;
use Ripple\Driver\Laravel\Listeners\FlushQueuedCookies;
use Ripple\Driver\Laravel\Listeners\FlushSessionState;
use Ripple\Utils\Output;

class RequestReceived
{
    public const LISTENERS = [
        FlushQueuedCookies::class,
        FlushSessionState::class,
        FlushAuthenticationState::class,
    ];

    /**
     * @param \Illuminate\Foundation\Application $app
     * @param \Illuminate\Foundation\Application $sandbox
     * @param \Illuminate\Http\Request           $request
     */
    public function __construct(public Application $app, public Application $sandbox, public Request $request)
    {
        foreach (RequestReceived::LISTENERS as $listener) {
            try {
                $app->make($listener)->handle($this);
            } catch (BindingResolutionException $e) {
                Output::warning($e->getMessage());
            }
        }

        ContainerMap::bind($sandbox);
    }
}
