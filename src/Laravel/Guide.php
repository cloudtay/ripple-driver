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

use Illuminate\Contracts\Foundation\Application;
use P\IO;
use P\Net;
use P\System;
use Psc\Core\Stream\Stream;
use Psc\Library\Net\Http\Server\HttpServer;
use Psc\Library\Net\Http\Server\Request;
use Psc\Library\Net\Http\Server\Response;
use Psc\Library\System\Process\Runtime;
use Psc\Library\System\Process\Task;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

use function P\run;

$guide = $class = new class () {
    private HttpServer $server;
    private string     $appPath;
    private string     $listen;
    private string     $threads;
    private Stream     $output;
    private string     $pipe = __FILE__ . '.lock';

    /**
     * @var Runtime[]
     */
    private array $runtimes = [];

    /**
     * @var array
     */
    private array $lastMessages = [];


    /**
     * @return void
     */
    public function run(): void
    {
        $this->initConfigure();

        $context      = \stream_context_create(['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
        $this->server = Net::Http()->server($this->listen, $context);
        $task         = System::Process()->task(function (HttpServer $server) {
            \cli_set_process_title('Laravel-PD-thread');

            /**
             * @var Application $application
             */
            $application = include_once $this->appPath . '/bootstrap/app.php';

            //            /**
            //             * @var Kernel $httpKernel
            //             */
            //            $httpKernel = $application->make(Kernel::class);

            $server->onRequest = function (
                Request  $request,
                Response $response
            ) use ($application) {

                $laravelRequest = new \Illuminate\Http\Request(
                    $request->query->all(),
                    $request->request->all(),
                    $request->attributes->all(),
                    $request->cookies->all(),
                    $request->files->all(),
                    $request->server->all(),
                    $request->getContent(),
                );

                $symfonyResponse = $application->handle($laravelRequest);

                $response->setStatusCode($symfonyResponse->getStatusCode());
                $response->setProtocolVersion($symfonyResponse->getProtocolVersion());
                $response->headers->add($symfonyResponse->headers->all());

                if ($symfonyResponse instanceof BinaryFileResponse) {
                    $response->setContent(
                        \fopen($symfonyResponse->getFile()->getPathname(), 'r'),
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
            $server->listen();
        });

        for ($i = 0; $i < $this->threads; $i++) {
            $this->guard($task);
        }

        $this->monitor();
        run();
    }

    /**
     * @return void
     */
    private function initConfigure(): void
    {
        \error_reporting(\E_ALL & ~\E_WARNING);
        \cli_set_process_title('Laravel-PD-guard');

        $this->pipe = __FILE__ . '.lock';

        if (!\file_exists($this->pipe)) {
            \posix_mkfifo($this->pipe, 0600);
        }

        $this->appPath = \getenv('P_RIPPLE_APP_PATH');
        $this->listen  = \getenv('P_RIPPLE_LISTEN');
        $this->threads = \getenv('P_RIPPLE_THREADS');

        include_once $this->appPath . '/vendor/autoload.php';

        $this->output = new Stream(\fopen($this->pipe, 'r+'));
        $this->output->setBlocking(false);
        $this->output->onReadable(function () {
            if ($this->output->read(1) === \PHP_EOL) {
                $this->output->close();
                foreach ($this->runtimes as $runtime) {
                    $runtime->stop();
                }
                \unlink($this->pipe);
                exit(0);
            }
        });
    }

    /**
     * @param Task $task
     * @return void
     */
    private function guard(Task $task): void
    {
        $runtime                                    = $task->run($this->server);
        $this->runtimes[\spl_object_hash($runtime)] = $runtime;
        $runtime->finally(function () use ($task, $runtime) {
            unset($this->runtimes[\spl_object_hash($runtime)]);

            $this->guard($task);
        });
        $this->printState();
    }

    /**
     * @return void
     */
    private function reload(): void
    {
        foreach ($this->runtimes as $runtime) {
            $runtime->stop();
        }
    }

    /**
     * @return void
     */
    private function monitor(): void
    {
        $monitor           = IO::File()->watch($this->appPath, 'php');
        $monitor->onTouch  = function (string $file) {
            $this->pushMessage("file touched: {$file}");
            $this->reload();
        };
        $monitor->onModify = function (string $file) {
            $this->pushMessage("file modified: {$file}");
            $this->reload();
        };
        $monitor->onRemove = function (string $file) {
            $this->pushMessage("file removed: {$file}");
            $this->reload();
        };
    }

    /**
     * ==========
     * 终端输出助手
     * ==========
     */

    /**
     * @param string $message
     * @return void
     */
    private function pushMessage(string $message): void
    {
        $this->lastMessages[] = $message;
        if (\count($this->lastMessages) > 1) {
            \array_shift($this->lastMessages);
        }
    }

    /**
     * @return void
     */
    private function printState(): void
    {
        $this->output->write("\033c");
        $pid  = \posix_getpid();
        $pids = [];
        foreach ($this->runtimes as $runtime) {
            $pids[] = $runtime->getProcessId();
        }
        $this->output->write("\033[1;34mPRipple\033[0m \033[1;32mLaunched\033[0m\n");
        $this->output->write("\n");
        $this->output->write($this->formatRow(['Compile'], 'info'));
        $this->output->write($this->formatRow(['PATH', $this->appPath], 'info'));
        $this->output->write($this->formatRow(['Listen', $this->listen], 'info'));
        $this->output->write($this->formatRow(["Threads", $this->threads], 'info'));
        $this->output->write("\n");
        $this->output->write($this->formatRow(["Master"], 'thread'));

        foreach ($pids as $pid) {
            $this->output->write($this->formatRow(["├─ {$pid} Thread", 'Running'], 'thread'));
        }

        $this->output->write("\n");
        foreach ($this->lastMessages as $message) {
            $this->output->write($this->formatList($message));
        }
    }

    /**
     * @param array  $row
     * @param string $type
     * @return string
     */
    private function formatRow(array $row, string $type = ''): string
    {
        $output    = '';
        $colorCode = $this->getColorCode($type);
        foreach ($row as $col) {
            $output .= \str_pad("{$colorCode}{$col}\033[0m", 40);
        }
        return $output . "\n";
    }

    /**
     * @param string $item
     * @return string
     */
    private function formatList(string $item): string
    {
        return "  - $item\n";
    }

    /**
     * @param string $type
     * @return string
     */
    private function getColorCode(string $type): string
    {
        return match ($type) {
            'info'   => "\033[1;36m",
            'thread' => "\033[1;33m",
            default  => "",
        };
    }
};

$guide->run();
