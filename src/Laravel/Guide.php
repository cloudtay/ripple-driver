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
use Psc\Drive\Utils\Console;
use Psc\Library\Net\Http\Server\HttpServer;
use Psc\Library\Net\Http\Server\Request;
use Psc\Library\Net\Http\Server\Response;
use Psc\Library\System\Process\Runtime;
use Psc\Library\System\Process\Task;
use Psc\Std\Stream\Exception\ConnectionException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use function P\run;

/**
 * 该文件为Laravel的启动引导文件,同时作为服务运行时的主程序,
 * 储存和维护子进程信息,并与客户端(控制端)通信
 * 引导文件运行于真空中,所有的输出又sh转发到stdin管道文件
 * 与控制端通信通过r+w双管道交互指令
 */
$guide = new class () {
    /**
     *
     */
    use Console;

    /**
     * @var HttpServer
     */
    private HttpServer $server;

    /**
     * @var string
     */
    private string $appPath;

    /**
     * @var string
     */
    private string $listen;

    /**
     * @var string
     */
    private string $threads;

    /**
     * @var Runtime[]
     */
    private array $runtimes = [];

    /**
     * @var array
     */
    private array $logs = [];

    /**
     * 服务输出流文件
     * @var string
     */
    private string $serialOutput = __DIR__ . '/Guide.php.output';

    /**
     * 服务输入流文件
     * @var string
     */
    private string $serialInput = __DIR__ . '/Guide.php.input';

    /**
     * 服务输出流
     * @var Stream
     */
    private Stream $serialOutputStream;

    /**
     * 服务输入流
     * @var Stream
     */
    private Stream $serialInputStream;

    /**
     * @var Task
     */
    private Task $task;

    /**
     *
     */
    public function __construct()
    {
        \error_reporting(\E_ALL & ~\E_WARNING);
        \cli_set_process_title('Laravel-PD-guard');
        \posix_setsid();

        $this->serialOutputStream = new Stream(\fopen($this->serialOutput, 'w+'));
        $this->serialInputStream  = new Stream(\fopen($this->serialInput, 'r'));

        $this->serialOutputStream->setBlocking(false);
        $this->serialInputStream->setBlocking(false);

        $this->appPath = \getenv('P_RIPPLE_APP_PATH');
        $this->listen  = \getenv('P_RIPPLE_LISTEN');
        $this->threads = \getenv('P_RIPPLE_THREADS');

        include_once $this->appPath . '/vendor/autoload.php';

        $context      = \stream_context_create(['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
        $this->server = Net::Http()->server($this->listen, $context);
        $this->task   = System::Process()->task(function (HttpServer $server) {
            \cli_set_process_title('Laravel-PD-thread');

            /**
             * @var Application $application
             */
            $application = include_once $this->appPath . '/bootstrap/app.php';

            /**
             * @param Request  $request
             * @param Response $response
             * @return void
             * @throws ConnectionException
             */
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

                $response->respond();
            };
            $server->listen();
        });
    }

    /**
     * @return void
     */
    private function addThread(): void
    {
        $runtime                                    = $this->task->run($this->server);
        $this->runtimes[\spl_object_hash($runtime)] = $runtime;

        $runtime->finally(function () use ($runtime) {
            unset($this->runtimes[\spl_object_hash($runtime)]);
            $this->addThread();
        });

        $this->flushState();
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
     * @param string $message
     * @return void
     */
    private function pushMessage(string $message): void
    {
        $this->logs[] = $message;
        if (\count($this->logs) > 1) {
            \array_shift($this->logs);
        }
    }

    /**
     * @return void
     */
    private function flushState(): void
    {
        $this->serialOutputStream->write("\033c");
        $processIds = [];
        foreach ($this->runtimes as $runtime) {
            $processIds[] = $runtime->getProcessId();
        }
        $this->serialOutputStream->write("\033[1;34mPRipple\033[0m \033[1;32mLaunched\033[0m\n");
        $this->serialOutputStream->write("\n");
        $this->serialOutputStream->write($this->formatRow(['Compile'], 'info'));
        $this->serialOutputStream->write($this->formatRow(['PATH', $this->appPath], 'info'));
        $this->serialOutputStream->write($this->formatRow(['Listen', $this->listen], 'info'));
        $this->serialOutputStream->write($this->formatRow(["Threads", $this->threads], 'info'));
        $this->serialOutputStream->write("\n");
        $this->serialOutputStream->write($this->formatRow(["Master"], 'thread'));

        foreach ($processIds as $pid) {
            $this->serialOutputStream->write($this->formatRow(["├─ {$pid} Thread", 'Running'], 'thread'));
        }

        $this->serialOutputStream->write("\n");
        foreach ($this->logs as $message) {
            $this->serialOutputStream->write($this->formatList($message));
        }
    }

    /**
     * @return void
     */
    public function launch(): void
    {
        for ($i = 0; $i < $this->threads; $i++) {
            $this->addThread();
        }

        $this->monitor();

        run();
    }
};

$guide->launch();
