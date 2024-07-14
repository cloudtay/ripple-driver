<?php declare(strict_types=1);

namespace Psc\Drive\Laravel;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use P\Net;
use P\System;
use Psc\Store\Net\Http\Server\Request;
use Psc\Store\Net\Http\Server\Response;
use Psc\Store\System\Exception\ProcessException;
use Psc\Store\System\Process\Task;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use function P\run;

/**
 * Class HttpService
 */
class PDrive extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'p:run 
    {--l|listen=http://127.0.0.1:8008}
    {--t|threads=4}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'start the P-Drive service';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        error_reporting(E_WARNING);

        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);

        $server            = Net::Http()->server($this->option('listen'), $context);
        $server->onRequest = function (Request $request, Response $response) {

            $laravelRequest = new \Illuminate\Http\Request(
                $request->query->all(),
                $request->request->all(),
                [],
                $request->attributes->all(),
                $request->files->all(),
                $request->server->all(),
                $request->getContent(),
            );

            $symfonyResponse = Container::getInstance()
                ->make(Application::class)
                ->handle($laravelRequest);

            $response->setStatusCode($symfonyResponse->getStatusCode());
            $response->setProtocolVersion($symfonyResponse->getProtocolVersion());
            $response->headers->add($symfonyResponse->headers->all());

            if ($symfonyResponse instanceof BinaryFileResponse) {
                $response->setContent(
                    fopen($symfonyResponse->getFile()->getPathname(), 'r'),
                );
            } else {
                $response->setContent(
                    $symfonyResponse->getContent(),
                    $symfonyResponse->headers->get('Content-Type')
                );
            }

            if ($symfonyResponse instanceof JsonResponse) {
                $response->headers->set('Content-Type', 'application/json');
            }

            $response->respond();
        };

        try {
            $task = System::Process()->task(fn() => $server->listen());
        } catch (ProcessException $e) {
            $this->error($e->getMessage());
            return;
        }

        for ($i = 0; $i < intval($this->option('threads')); $i++) {
            $this->guard($task);
        }

        run();
    }

    /**
     * @param Task $task
     * @return void
     */
    private function guard(Task $task): void
    {
        $task->run()->except(function () use ($task) {
            $this->guard($task);
        });
    }
}
