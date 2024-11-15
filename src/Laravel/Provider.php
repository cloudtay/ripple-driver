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

namespace Ripple\Driver\Laravel;

use Illuminate\Support\ServiceProvider;
use Ripple\Worker\Manager;

use function config_path;

class Provider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->commands([Driver::class]);
        $this->app->singleton(Manager::class, static function () {
            return new Manager();
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/ripple.php' => config_path('ripple.php'),
        ], 'ripple-config');
    }
}
