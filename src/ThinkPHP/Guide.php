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

use JetBrains\PhpStorm\NoReturn;
use P\IO;
use P\Net;
use P\System;
use Psc\Core\Stream\Stream;
use Psc\Drive\Stream\Command;
use Psc\Drive\Stream\Frame;
use Psc\Drive\Utils\Console;
use Psc\Library\Net\Http\Server\HttpServer;
use Psc\Library\Net\Http\Server\Request;
use Psc\Library\Net\Http\Server\Response;
use Psc\Library\System\Process\Runtime;
use Psc\Library\System\Process\Task;
use think\App;
use think\response\File;

use function P\run;

$appPath = \getenv('P_RIPPLE_APP_PATH');
$listen  = \getenv('P_RIPPLE_LISTEN');
$threads = \getenv('P_RIPPLE_THREADS');

\error_reporting(\E_ALL & ~\E_WARNING);
\cli_set_process_title('ThinkPHP-guard');
\file_put_contents(__DIR__ . '/Guide.php.pid', \posix_getpid());
\posix_setsid();

include_once $appPath . '/vendor/autoload.php';

/**
 * 该文件为ThinkPHP的启动引导文件,同时作为服务运行时的主程序,
 * 储存和维护子进程信息,并与客户端(控制端)通信
 * 引导文件运行于真空中,所有的输出又sh转发到stdin管道文件
 * 与控制端通信通过r+w双管道交互指令
 */
$guide = new class (
    $appPath,
    $listen,
    $threads,
) {
    /**
     *
     */
    use Console;

    /**
     * @var HttpServer
     */
    private HttpServer $server;

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
     * @var Frame
     */
    private Frame $frame;

    /**
     * @param string $appPath
     * @param string $listen
     * @param string $threads
     */
    public function __construct(
        private readonly string $appPath,
        private readonly string $listen,
        private readonly string $threads,
    ) {
        $this->serialOutputStream = new Stream(\fopen($this->serialOutput, 'w+'));
        $this->serialInputStream  = new Stream(\fopen($this->serialInput, 'r+'));

        $this->serialOutputStream->setBlocking(false);
        $this->serialInputStream->setBlocking(false);

        $this->frame = new Frame();

        /**
         * 控制台指令监听
         */
        $this->serialInputStream->onReadable(function () {
            foreach ($this->frame->decodeStream($this->serialInputStream->read(1024)) as $content) {
                $this->onCommand(\unserialize($content));
            }
        });

        /**
         * Http服务监听
         */
        $context      = \stream_context_create(['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
        $this->server = Net::Http()->server($this->listen, $context);
        $this->task   = System::Process()->task(function (HttpServer $server) {
            \cli_set_process_title('ThinkPHP-worker');

            $app  = new App();
            $http = $app->http;

            $server->onRequest = function (Request $request, Response $response) use ($http) {
                $thinkRequest = new \think\Request();
                $thinkRequest->setUrl($request->getUri());
                $thinkRequest->setBaseUrl($request->getBaseUrl());
                $thinkRequest->setHost($request->getHost());
                $thinkRequest->setPathinfo($request->getPathInfo());
                $thinkRequest->setMethod($request->getMethod());
                $thinkRequest->withHeader($request->headers->all());
                $thinkRequest->withCookie($request->cookies->all());
                $thinkRequest->withFiles($request->files->all());
                $thinkRequest->withGet($request->query->all());
                $thinkRequest->withInput($request->getContent());

                $thinkResponse = $http->run($thinkRequest);

                $response->setStatusCode($thinkResponse->getCode());
                $response->headers->add($thinkResponse->getHeader());

                if ($thinkResponse instanceof File) {
                    $response->setContent(
                        IO::File()->open($thinkResponse->getData(), 'r+')
                    );
                } else {
                    $response->setContent(
                        $thinkResponse->getData(),
                        $thinkResponse->getHeader('Content-Type')
                    );
                }
                $response->respond();
            };

            $server->listen();
        });
    }

    /**
     * @param Command $command
     * @return void
     */
    private function onCommand(Command $command): void
    {
        switch ($command->name) {
            case 'status':
                $command->setResult($this->renderState());
                $this->serialOutputStream->write($this->frame->encodeFrame($command->__toString()));
                break;
            case 'stop':
                foreach ($this->runtimes as $runtime) {
                    $runtime->stop();
                }
                $command->setResult(
                    'services stopped.' . \PHP_EOL
                );

                $this->serialOutputStream->write($this->frame->encodeFrame($command->__toString()));

                $this->serialOutputStream->close();
                $this->serialInputStream->close();

                \unlink(__DIR__ . '/Guide.php.pid');

                exit(0);
                break;
            default:
                break;
        }
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
            $this->recordLog("file touched: {$file}");
            $this->reload();
        };
        $monitor->onModify = function (string $file) {
            $this->recordLog("file modified: {$file}");
            $this->reload();
        };
        $monitor->onRemove = function (string $file) {
            $this->recordLog("file removed: {$file}");
            $this->reload();
        };
    }

    /**
     * @param string $message
     * @return void
     */
    private function recordLog(string $message): void
    {
        $this->logs[] = $message;
        if (\count($this->logs) > 1) {
            \array_shift($this->logs);
        }
    }

    /**
     * @return string
     */
    private function renderState(): string
    {
        $content    = '';
        $processIds = [];
        foreach ($this->runtimes as $runtime) {
            $processIds[] = $runtime->getProcessId();
        }
        $content .= ("\033[1;34mPRipple\033[0m \033[1;32mLaunched\033[0m\n");
        $content .= ("\n");
        $content .= ($this->formatRow(['Compile'], 'info'));
        $content .= ($this->formatRow(['PATH', $this->appPath], 'info'));
        $content .= ($this->formatRow(['Listen', $this->listen], 'info'));
        $content .= ($this->formatRow(["Threads", $this->threads], 'info'));
        $content .= ("\n");
        $content .= ($this->formatRow(["Master"], 'thread'));

        foreach ($processIds as $pid) {
            $content .= ($this->formatRow(["├─ {$pid} Thread", 'Running'], 'thread'));
        }

        $content .= ("\n");
        foreach ($this->logs as $message) {
            $content .= ($this->formatList($message));
        }

        return $content;
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

    /**
     *
     */
    #[NoReturn] public function __destruct()
    {
        foreach ($this->runtimes as $runtime) {
            $runtime->stop();
        }

        $this->serialOutputStream->close();
        $this->serialInputStream->close();

        \unlink(__DIR__ . '/Guide.php.pid');

        exit(0);
    }
};

$guide->launch();
