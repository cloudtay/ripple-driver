<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Ripple\Driver\Laravel;

use Co\IO;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Ripple\Driver\Laravel\Events\RequestHandled;
use Ripple\Driver\Laravel\Events\RequestReceived;
use Ripple\Driver\Laravel\Events\RequestTerminated;
use Ripple\Driver\Laravel\Events\WorkerErrorOccurred;
use Ripple\Driver\Laravel\Response\IteratorResponse;
use Ripple\Driver\Laravel\Traits\DispatchesEvents;
use Ripple\Driver\Utils\Console;
use Ripple\Driver\Utils\Guard;
use Ripple\Http\Server;
use Ripple\Http\Server\Request;
use Ripple\Worker\Manager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

use function base_path;
use function cli_set_process_title;
use function file_exists;
use function fopen;
use function fwrite;

use const STDOUT;

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class Worker extends \Ripple\Worker
{
    use Console;
    use DispatchesEvents;

    /*** @var Server */
    private Server $server;

    /*** @var Application */
    private Application $application;

    /**
     * @param string $address
     * @param int    $count
     */
    public function __construct(
        private readonly string $address = 'http://127.0.0.1:8008',
        int                     $count = 4,
    ) {
        $this->name  = 'http-server';
        $this->count = $count;
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
        cli_set_process_title('laravel-guard');

        /*** output worker*/
        fwrite(STDOUT, $this->formatRow(['Worker', $this->getName()]));

        /*** output env*/
        fwrite(STDOUT, $this->formatRow(["- Conf"]));
        foreach (Driver::DECLARE_OPTIONS as $key => $type) {
            fwrite(STDOUT, $this->formatRow([
                $key,
                \Ripple\Driver\Utils\Config::value2string(Config::get("ripple.{$key}"), $type),
            ]));
        }

        /*** output logs*/
        fwrite(STDOUT, $this->formatRow(["- Logs"]));

        $server = new Server($this->address, [
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1
            ]
        ]);

        $this->server      = $server;
        $this->application = Application::getInstance();

        if (\Ripple\Driver\Utils\Config::value2bool(Config::get('ripple.HTTP_RELOAD'))) {
            $monitor = IO::File()->watch();
            $monitor->add(base_path('app'));
            $monitor->add(base_path('bootstrap'));
            $monitor->add(base_path('config'));
            $monitor->add(base_path('routes'));
            $monitor->add(base_path('resources'));
            if (file_exists(base_path('.env'))) {
                $monitor->add(base_path('.env'));
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
        cli_set_process_title('laravel-worker');

        $this->application->bind(Worker::class, fn () => $this);

        $this->server->onRequest(function (
            Request $request
        ) {
            $application = clone $this->application;
            /*** @var Kernel $kernel */
            $kernel = $application->make(Kernel::class);

            $laravelRequest = new \Illuminate\Http\Request(
                $request->GET,
                $request->POST,
                [],
                $request->COOKIE,
                $request->FILES,
                $request->SERVER,
                $request->CONTENT,
            );
            $laravelRequest->attributes->set('ripple.request', $request);
            $this->dispatchEvent($application, new RequestReceived($this->application, $application, $laravelRequest));

            try {
                $laravelResponse = $kernel->handle($laravelRequest);
                $response        = $request->getResponse();
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

                $kernel->terminate($laravelRequest, $laravelResponse);
                $this->dispatchEvent($application, new RequestTerminated($this->application, $application, $laravelRequest, $laravelResponse));
            } catch (Throwable $e) {
                $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
            } finally {
                unset($laravelRequest, $response, $laravelResponse, $kernel);
                unset($application);
            }
        });
        $this->server->listen();
    }
}
