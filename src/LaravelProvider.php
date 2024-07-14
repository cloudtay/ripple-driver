<?php declare(strict_types=1);

namespace Psc\Drive;

use Illuminate\Support\ServiceProvider;
use Psc\Drive\Laravel\PDrive;

class LaravelProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->commands([PDrive::class]);
    }
}
