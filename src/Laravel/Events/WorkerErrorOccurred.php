<?php declare(strict_types=1);

namespace Psc\Drive\Laravel\Events;

use Illuminate\Foundation\Application;
use Throwable;

class WorkerErrorOccurred
{
    public function __construct(
        public Application $app,
        public Application $sandbox,
        public Throwable $exception
    ) {
    }
}
