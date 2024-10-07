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

namespace Psc\Drive\Laravel;

use Co\IO;
use Co\Net;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use JetBrains\PhpStorm\NoReturn;
use Psc\Core\Http\Server\Request;
use Psc\Core\Http\Server\Server;
use Psc\Drive\Laravel\Events\RequestHandled;
use Psc\Drive\Laravel\Events\RequestReceived;
use Psc\Drive\Laravel\Events\RequestTerminated;
use Psc\Drive\Laravel\Events\WorkerErrorOccurred;
use Psc\Drive\Laravel\Response\GeneratorResponse;
use Psc\Drive\Laravel\Traits\DispatchesEvents;
use Psc\Drive\Utils\Console;
use Psc\Utils\Output;
use Psc\Worker\Command;
use Psc\Worker\Manager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

use function base_path;
use function cli_set_process_title;
use function Co\cancelAll;
use function file_exists;
use function fopen;
use function fwrite;
use function in_array;
use function stream_context_create;

use const STDOUT;

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class Worker extends \Psc\Worker\Worker
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
     * @param bool   $sandbox
     */
    public function __construct(
        private readonly string $address = 'http://127.0.0.1:8008',
        private readonly int    $count = 4,
        private readonly bool   $sandbox = true,
    ) {
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
        foreach (Driver::DECLARE_OPTIONS as $key => $value) {
            fwrite(STDOUT, $this->formatRow(["{$key}", Config::get("ripple.{$key}", $value) ?: 'off']));
        }

        /*** output logs*/
        fwrite(STDOUT, $this->formatRow(["- Logs"]));

        $context = stream_context_create(['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
        $server  = Net::Http()->server($this->address, $context);
        if (!$server instanceof Server) {
            Output::error('Server not supported');
            exit(1);
        }
        $this->server = $server;

        $this->application = Application::getInstance();

        if (in_array(Config::get('ripple.HTTP_RELOAD'), ['true', '1', 'on', true, 1], true)) {
            $monitor = IO::File()->watch();
            $monitor->add(base_path('app'));
            $monitor->add(base_path('bootstrap'));
            $monitor->add(base_path('config'));
            $monitor->add(base_path('database'));
            $monitor->add(base_path('routes'));
            $monitor->add(base_path('resources'));
            if (file_exists(base_path('.env'))) {
                $monitor->add(base_path('.env'));
            }

            $monitor->onTouch = function (string $file) use ($manager) {
                $manager->reload($this->getName());
                Output::writeln("File {$file} touched");
            };

            $monitor->onModify = function (string $file) use ($manager) {
                $manager->reload($this->getName());
                Output::writeln("File {$file} modify");
            };

            $monitor->onRemove = function (string $file) use ($manager) {
                $manager->reload($this->getName());
                Output::writeln("File {$file} remove");
            };

            $monitor->run();
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 11:06
     * @return string
     */
    public function getName(): string
    {
        return 'http-server';
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
            $application = $this->sandbox ? clone $this->application : $this->application;
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
                $response = $request->getResponse();
                $response->setStatusCode($laravelResponse->getStatusCode());
                $response->setProtocolVersion($laravelResponse->getProtocolVersion());
                $response->headers->add($laravelResponse->headers->all());
                if ($laravelResponse instanceof BinaryFileResponse) {
                    $response->setContent(fopen($laravelResponse->getFile()->getPathname(), 'r+'));
                } elseif ($laravelResponse instanceof GeneratorResponse) {
                    $response->setContent($laravelResponse->getGenerator());
                } else {
                    $response->setContent($laravelResponse->getContent());
                }

                $response->respond();
                $this->dispatchEvent($application, new RequestHandled($this->application, $application, $laravelRequest, $laravelResponse));

                $kernel->terminate($laravelRequest, $response);
                $this->dispatchEvent($application, new RequestTerminated($this->application, $application, $laravelRequest, $laravelResponse));
            } catch (Throwable $e) {
                $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
            } finally {
                if ($this->sandbox) {
                    unset($application);
                }
                unset($laravelRequest, $response, $laravelResponse, $kernel);
            }
        });
        $this->server->listen();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 11:06
     * @return int
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 23:39
     *
     * @param Command $workerCommand
     *
     * @return void
     */
    public function onCommand(Command $workerCommand): void
    {
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 11:35
     * @return void
     */
    #[NoReturn] public function onReload(): void
    {
        cancelAll();
        exit(0);
    }
}
