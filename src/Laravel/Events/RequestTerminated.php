<?php declare(strict_types=1);

namespace Psc\Drive\Laravel\Events;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestTerminated
{
    public function __construct(
        public Application $app,
        public Application $sandbox,
        public Request $request,
        public Response $response
    ) {
    }
}
