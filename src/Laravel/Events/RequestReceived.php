<?php declare(strict_types=1);

namespace Psc\Drive\Laravel\Events;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

class RequestReceived
{
    public function __construct(
        public Application $app,
        public Application $sandbox,
        public Request $request
    ) {
    }
}
