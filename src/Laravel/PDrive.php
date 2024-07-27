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

use Illuminate\Console\Command;
use JetBrains\PhpStorm\NoReturn;
use P\System;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Output;
use Psc\Core\Stream\Stream;
use Psc\Drive\Stream\Frame;
use Psc\Std\Stream\Exception\ConnectionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function base_path;
use function cli_set_process_title;
use function file_exists;
use function fopen;
use function P\async;
use function P\await;
use function P\onSignal;
use function P\run;
use function posix_mkfifo;
use function putenv;
use function sprintf;
use function unserialize;

use const PHP_BINARY;
use const SIGINT;

/**
 * 驱动使用proc_open指令运行Laravel服务并嫁接sh输出至serialStdin中,
 * 与服务实体的通讯采用r+w双线管道模式传呼指令,该类只负责启动|通讯服务,
 * 不作为信息的载体
 */
class PDrive extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'p:server
    {action=start : The action to perform ,Support start|stop|status}
    {--s|listen=http://127.0.0.1:8008 : The address to listen on,default http://127.0.0.1:8008}
    {--t|threads=4 : The number of threads to use,default 4}
    {--d|daemon : Run the service in the background}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'start the P-Drive service';

    /**
     * 服务输出流文件
     * @var string
     */
    private string $serialOutput = __DIR__ . '/Guide.php.output';

    /**
     * 终端输入流文件
     * @var string
     */
    private string $serialStdin = __DIR__ . '/Guide.php.stdin';

    /**
     * 服务输入流文件
     * @var string
     */
    private string $serialInput = __DIR__ . '/Guide.php.input';

    /**
     * 服务输出流文件
     * @var Stream
     */
    private Stream $serialOutputStream;

    /**
     * 终端输入流文件
     * @var Stream
     */
    private Stream $serialStdinStream;

    /**
     * 服务输入流文件
     * @var Stream
     */
    private Stream $serialInputStream;

    /**
     * @var Frame
     */
    private Frame $frame;

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return void
     */
    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        if (!file_exists($this->serialInput)) {
            posix_mkfifo($this->serialInput, 0600);
        }

        if (!file_exists($this->serialOutput)) {
            posix_mkfifo($this->serialOutput, 0600);
        }

        if (!file_exists($this->serialStdin)) {
            posix_mkfifo($this->serialStdin, 0600);
        }

        $this->serialOutputStream = new Stream(fopen($this->serialOutput, 'r+'));
        $this->serialStdinStream  = new Stream(fopen($this->serialStdin, 'r+'));
        $this->serialInputStream  = new Stream(fopen($this->serialInput, 'w+'));
        $this->serialOutputStream->setBlocking(false);
        $this->serialStdinStream->setBlocking(false);

        $this->frame = new Frame();

        /**
         *
         */
        $this->serialOutputStream->onReadable(function () {
            $content = $this->serialOutputStream->read(8192);
            foreach ($this->frame->decodeStream($content) as $command) {
                $this->onCommand(unserialize($command));
            }
        });
    }

    /**
     * 运行服务
     * @return void
     * @throws ConnectionException
     */
    public function handle(): void
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'start':
                $this->serialStdinStream->onReadable(function () {
                    echo $this->serialStdinStream->read(8192);
                });

                $this->start();
                break;

            case 'stop':
                if (!file_exists(__DIR__ . '/Guide.php.pid')) {
                    Output::warning('The service is not running.');
                    exit(0);
                }

                $this->inputCommand('stop')->then(function (string $result) {
                    echo $result;
                    exit(0);
                });
                run();

            // no break
            case 'status':
                $this->listen();
                run();

            // no break
            default:
                Output::warning('Invalid action.');
                break;
        }
    }

    /**
     * @return void
     * @throws ConnectionException
     */
    private function start(): void
    {
        cli_set_process_title('Laravel-PD');
        $appPath = base_path();

        $listen  = $this->option('listen');
        $threads = $this->option('threads');

        putenv("P_RIPPLE_APP_PATH=$appPath");
        putenv("P_RIPPLE_LISTEN=$listen");
        putenv("P_RIPPLE_THREADS=$threads");

        if (file_exists(__DIR__ . '/Guide.php.pid')) {
            Output::warning('The service is already running.');
            Output::warning("file exists: {$this->serialStdin}");
            exit(0);
        }

        $command = sprintf(
            "%s %s > %s",
            PHP_BINARY,
            __DIR__ . '/Guide.php',
            $this->serialStdin
        );

        $session = System::Proc()->open();
        $session->input($command);

        $this->inputCommand('status')->then(function (string $result) {
            echo $result;

            if ($this->option('daemon')) {
                exit(0);
            } else {
                onSignal(SIGINT, function () {
                    $this->inputCommand('stop')->then(function (string $result) {
                        echo $result;
                        exit(0);
                    });
                });

                $this->listen();
            }
        });

        run();
    }

    /**
     * @return void
     */
    private function listen(): void
    {
        $this->serialStdinStream->onReadable(function () {
            echo $this->serialStdinStream->read(8192);
        });

        if (!file_exists(__DIR__ . '/Guide.php.pid')) {
            Output::warning('The service is not running.');
            exit(0);
        }

        async(function () {
            while (1) {
                $result = await($this->inputCommand('status'));
                echo "\033c";
                echo $result;
                \P\sleep(1);
            }
        });
    }

    /**
     * @var array
     */
    private array $queue = [];

    /**
     * @param string $name
     * @param array  $arguments
     * @param array  $options
     * @return Promise
     * @throws ConnectionException
     */
    private function inputCommand(
        string $name,
        array  $arguments = [],
        array  $options = []
    ): Promise
    {
        $command = new \Psc\Drive\Stream\Command(
            $name,
            $arguments,
            $options
        );

        $this->serialInputStream->write($this->frame->encodeFrame($command->__toString()));

        return \P\promise(function ($r, $d) use ($command) {
            $this->queue[$command->id] = [
                'resolve' => $r,
                'reject'  => $d,
            ];
        });
    }

    /**
     * @param \Psc\Drive\Stream\Command $command
     * @return void
     */
    private function onCommand(\Psc\Drive\Stream\Command $command): void
    {
        if (isset($this->queue[$command->id])) {
            $this->queue[$command->id]['resolve']($command->result);

            unset($this->queue[$command->id]);
        }

        // 忽略无效指令响应
    }

    #[NoReturn] public function __destruct()
    {
        exit(0);
    }
}
