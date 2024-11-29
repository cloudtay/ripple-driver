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

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Driver\Laravel\Events\RequestHandled;
use Ripple\Driver\Laravel\Events\RequestReceived;
use Ripple\Driver\Laravel\Events\RequestTerminated;
use Ripple\Driver\Laravel\Events\WorkerErrorOccurred;
use Ripple\Driver\Laravel\Response\IteratorResponse;
use Ripple\Driver\Laravel\Traits\DispatchesEvents;
use Ripple\Driver\Utils\Config;
use Ripple\Driver\Utils\Console;
use Ripple\Driver\Utils\Guard;
use Ripple\File\File;
use Ripple\Http\Server as HttpServer;
use Ripple\Http\Server\Request;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;
use Ripple\Worker\Worker;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

use function cli_set_process_title;
use function Co\async;
use function Co\channel;
use function Co\lock;
use function Co\onSignal;
use function Co\wait;
use function define;
use function file_exists;
use function fopen;
use function fwrite;
use function getenv;
use function intval;
use function is_dir;
use function is_file;
use function realpath;

use const SIGINT;
use const SIGQUIT;
use const SIGTERM;
use const STDOUT;

$env = getenv();

if (!$projectPath = $env['RIP_PROJECT_PATH'] ?? null) {
    echo "project set the RIP_PROJECT_PATH environment variable\n";
    exit(1);
}

if (!is_dir($projectPath)) {
    echo "the RIP_PROJECT_PATH environment variable is not a valid directory\n";
    exit(1);
}

if (!is_file("{$projectPath}/vendor/autoload.php")) {
    echo "the vendor/autoload.php file was not found in the project directory\n";
    exit(1);
}

$projectPath = realpath($projectPath);

require "{$projectPath}/vendor/autoload.php";

define("RIP_PROJECT_PATH", $projectPath);

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class Server extends Worker
{
    use Console;
    use DispatchesEvents;

    /*** @var HttpServer */
    private HttpServer $server;

    /*** @var Application */
    private Application $application;

    /**
     * @param string $address
     * @param int    $count
     * @param bool   $reload
     */
    public function __construct(private readonly string $address, protected int $count, private readonly bool $reload = false)
    {
        $this->name = 'http-server';
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 23:34
     *
     * @param Manager $manager
     *
     * @return void
     * @throws Throwable
     */
    public function register(Manager $manager): void
    {
        /*** output worker*/
        fwrite(STDOUT, $this->formatRow(['Worker', $this->getName()]));

        /*** output env*/
        fwrite(STDOUT, $this->formatRow(["Conf"]));
        fwrite(STDOUT, $this->formatRow(["- Listen", $this->address]));
        fwrite(STDOUT, $this->formatRow(["- Workers", $this->count]));
        fwrite(STDOUT, $this->formatRow(["- Reload", Config::value2string($this->reload, 'bool')]));

        /*** output logs*/
        fwrite(STDOUT, $this->formatRow(["Logs"]));

        /*** initialize*/
        $this->server = new HttpServer($this->address, ['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
        if ($this->reload) {
            $monitor = File::getInstance()->monitor();
            $monitor->add(RIP_PROJECT_PATH . ('/app'));
            $monitor->add(RIP_PROJECT_PATH . ('/bootstrap'));
            $monitor->add(RIP_PROJECT_PATH . ('/config'));
            $monitor->add(RIP_PROJECT_PATH . ('/routes'));
            $monitor->add(RIP_PROJECT_PATH . ('/resources'));
            if (file_exists(RIP_PROJECT_PATH . ('/.env'))) {
                $monitor->add(RIP_PROJECT_PATH . ('/.env'));
            }
            Guard::relevance($manager, $this, $monitor);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 11:08
     * @return void
     */
    public function boot(): void
    {
        try {
            onSignal(SIGINT, static fn () => exit(0));
            onSignal(SIGTERM, static fn () => exit(0));
            onSignal(SIGQUIT, static fn () => exit(0));
        } catch (UnsupportedFeatureException $e) {
            Output::warning("signal registration failure may cause the program to fail to exit normally: {$e->getMessage()}");
        }

        cli_set_process_title('laravel-worker');

        $this->application = Server::createApplication();

        try {
            $kernel = $this->application->make(Kernel::class);
        } catch (BindingResolutionException $e) {
            Output::warning("kernel resolution failed: {$e->getMessage()}");
            exit(1);
        }

        $kernel->bootstrap();

        $this->application->loadDeferredProviders();
        foreach (Server::defaultServicesToWarm() as $service) {
            try {
                $this->application->make($service);
            } catch (Throwable $e) {
            }
        }

        $this->server->onRequest(function (Request $request) {
            $laravelRequest = new \Illuminate\Http\Request(
                $request->GET,
                $request->POST,
                [],
                $request->COOKIE,
                $request->FILES,
                $request->SERVER,
                $request->CONTENT,
            );

            $application = clone $this->application;
            $application->instance('request', $laravelRequest);
            $this->dispatchEvent($application, new RequestReceived($this->application, $application, $laravelRequest));

            try {
                /*** @var Kernel $kernel */
                $kernel          = $application->make(Kernel::class);
                $laravelResponse = $kernel->handle($laravelRequest);

                /*** handle response*/
                $response = $request->getResponse();
                $response->setStatusCode($laravelResponse->getStatusCode());

                foreach ($laravelResponse->headers->allPreserveCaseWithoutCookies() as $key => $value) {
                    $response->withHeader($key, $value);
                }

                foreach ($laravelResponse->headers->getCookies() as $cookie) {
                    $response->withCookie($cookie->getName(), $cookie->__toString());
                }

                if ($laravelResponse instanceof BinaryFileResponse) {
                    $response->setContent(fopen($laravelResponse->getFile()->getPathname(), 'r+'));
                } elseif ($laravelResponse instanceof IteratorResponse) {
                    $response->setContent($laravelResponse->getIterator());
                } else {
                    $response->setContent($laravelResponse->getContent());
                }

                $response->respond();
                $this->dispatchEvent($application, new RequestHandled($this->application, $application, $laravelRequest, $laravelResponse));

                /*** terminate*/
                $kernel->terminate($laravelRequest, $laravelResponse);
                $this->dispatchEvent($application, new RequestTerminated($this->application, $application, $laravelRequest, $laravelResponse));
            } catch (Throwable $e) {
                $request->respond($e->getMessage(), [], $e->getCode());
                $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
            } finally {
                $application->flush();
                unset($laravelRequest, $laravelResponse, $request, $response, $kernel, $application);
            }
        });

        $this->server->listen();
    }

    /*** @return \Illuminate\Foundation\Application */
    public static function createApplication(): Application
    {
        return Application::configure(basePath: RIP_PROJECT_PATH)
            ->withRouting(
                web: RIP_PROJECT_PATH . '/routes/web.php',
                commands: RIP_PROJECT_PATH . '/routes/console.php',
                health: '/up',
            )
            ->withMiddleware(function (Middleware $middleware) {
            })
            ->withExceptions(function (Exceptions $exceptions) {
                //
            })->create();
    }

    /**
     * @return string[]
     */
    public static function defaultServicesToWarm(): array
    {
        return [
            'auth',
            'cache',
            'cache.store',
            'config',
            'cookie',
            'db',
            'db.factory',
            'db.transactions',
            'encrypter',
            'files',
            'hash',
            'log',
            'router',
            'routes',
            'session',
            'session.store',
            'translator',
            'url',
            'view',
        ];
    }
}

$lock = lock(RIP_PROJECT_PATH);
if (!$lock->exclusion(false)) {
    echo "the server is already running\n";
    exit(0);
}

cli_set_process_title('laravel-guard');

$channel = channel(RIP_PROJECT_PATH, true);
$listen  = Config::value2string($env['RIP_HTTP_LISTEN'] ?? 'http://127.0.0.1:8008', 'string');
$workers = Config::value2string($env['RIP_HTTP_WORKERS'] ?? 4, 'string');
$reload  = Config::value2bool($env['RIP_HTTP_RELOAD'] ?? false);

$worker  = new Server($listen, intval($workers), $reload);
$manager = new Manager();
$manager->addWorker($worker);
$manager->run();

/*** Guardian part*/
try {
    onSignal(SIGINT, function () use ($manager) {
        $manager->stop();
        exit(0);
    });
    onSignal(SIGTERM, function () use ($manager) {
        $manager->stop();
        exit(0);
    });
    onSignal(SIGQUIT, function () use ($manager) {
        $manager->stop();
        exit(0);
    });

    async(static function () use ($manager, $channel) {
        while (1) {
            $control = $channel->receive();
            if ($control === 'stop') {
                $manager->stop();
                exit(0);
            }

            if ($control === 'reload') {
                $manager->reload();
            }
        }
    });
} catch (UnsupportedFeatureException $e) {
    Output::warning("Signal registration failure may cause the program to fail to exit normally: {$e->getMessage()}");
}

wait();
