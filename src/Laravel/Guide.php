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

use P\IO;
use P\Net;
use P\System;
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

    public function run(): void
    {
        error_reporting(E_ALL & ~E_WARNING);
        $this->appPath = \getenv('P_RIPPLE_APP_PATH');
        $this->listen  = \getenv('P_RIPPLE_LISTEN');
        $this->threads = \getenv('P_RIPPLE_THREADS');

        include_once $this->appPath . '/vendor/autoload.php';

        $context      = \stream_context_create(['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
        $this->server = Net::Http()->server($this->listen, $context);
        $task         = System::Process()->task(function (HttpServer $server) {
            $application       = include_once $this->appPath . '/bootstrap/app.php';
            $server->onRequest = function (
                Request  $request,
                Response $response
            ) use ($application) {
                $laravelRequest = new \Illuminate\Http\Request(
                    $request->query->all(),
                    $request->request->all(),
                    [],
                    $request->attributes->all(),
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
     * @param Task $task
     * @return void
     */
    private function guard(Task $task): void
    {
        $runtime = $task->run($this->server);
        $runtime->finally(function () use ($task) {
            $this->guard($task);
        });
        $this->runtimes[] = $runtime;
        $this->printState();
    }

    /**
     * @return void
     */
    private function reload(): void
    {
        while ($runtime = \array_shift($this->runtimes)) {
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
            $this->pushMessage("File touched: {$file}");
            $this->reload();
        };
        $monitor->onModify = function (string $file) {
            $this->pushMessage("File modified: {$file}");
            $this->reload();
        };
        $monitor->onRemove = function (string $file) {
            $this->pushMessage("File removed: {$file}");
            $this->reload();
        };
    }

    /**
     * @var Runtime[]
     */
    private array $runtimes = [];

    /**
     * @var array
     */
    private array $lastMessages = [];

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
        echo "\033c";
        $pid  = \posix_getpid();
        $pids = [];
        foreach ($this->runtimes as $runtime) {
            $pids[] = $runtime->getProcessId();
        }
        echo "\033[1;34mPRipple\033[0m \033[1;32mLaunched\033[0m\n";
        echo "\n";
        echo $this->formatRow(['App Path', $this->appPath], 'info');
        echo $this->formatRow(['Listen', $this->listen], 'info');
        echo $this->formatRow(["Threads", $this->threads], 'info');
        echo "\n";
        echo $this->formatRow(["Master"], 'thread');
        foreach ($pids as $pid) {
            echo $this->formatRow(["├─ {$pid} Thread", 'Running'], 'thread');
        }
        echo "\n";
        foreach ($this->lastMessages as $message) {
            echo $this->formatList($message);
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
