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
use Illuminate\Support\Env;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Core\Stream\Stream;
use Psc\Utils\Output;
use Psc\Utils\Serialization\Zx7e;
use Psc\Worker\Manager;
use Revolt\EventLoop\UnsupportedFeatureException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function app;
use function base_path;
use function boolval;
use function Co\cancelAll;
use function Co\onSignal;
use function Co\tick;
use function file_exists;
use function fopen;
use function intval;
use function json_decode;
use function posix_mkfifo;
use function shell_exec;
use function sprintf;
use function storage_path;
use function touch;
use function unlink;

use const PHP_BINARY;
use const PHP_OS_FAMILY;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

/**
 * @Author cclilshy
 * @Date   2024/8/17 16:16
 */
class PDrive extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'p:server
    {action=start : The action to perform ,Support start|stop|reload|status}
    {--d|daemon : Run the server in the background}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'start the P-Drive service';

    /**
     * @var Manager
     */
    private Manager $manager;

    /**
     * @var string
     */
    private string $controlPipePath;

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     */
    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->manager         = app(Manager::class);
        $this->controlPipePath = storage_path('control.pipe');
    }

    /**
     * 运行服务
     *
     * @return void
     * @throws ConnectionException|UnsupportedFeatureException
     */
    public function handle(): void
    {
        $zx7e = new Zx7e();
        switch ($this->argument('action')) {
            case 'start':
                if (file_exists($this->controlPipePath)) {
                    Output::warning('The server is already running');
                    return;
                }
                if (!$this->option('daemon')) {
                    $this->start();
                    tick();
                } else {
                    $command = sprintf(
                        '%s %s p:server start > %s &',
                        PHP_BINARY,
                        base_path('artisan'),
                        storage_path('logs/prp.log')
                    );
                    shell_exec($command);
                    Output::writeln('server started');
                }
                exit(0);
            case 'stop':
                if (!file_exists($this->controlPipePath)) {
                    Output::warning('The server is not running');
                    return;
                }
                $controlStream = new Stream(fopen($this->controlPipePath, 'r+'));
                $controlStream->write($zx7e->encodeFrame('{"action":"stop"}'));
                Output::writeln('The server is stopping');
                break;
            case 'reload':
                if (!file_exists($this->controlPipePath)) {
                    Output::warning('The server is not running');
                    return;
                }
                $controlStream = new Stream(fopen($this->controlPipePath, 'r+'));
                $controlStream->write($zx7e->encodeFrame('{"action":"reload"}'));
                Output::writeln('The server is reloading');
                break;
            case 'status':
                if (!file_exists($this->controlPipePath)) {
                    Output::writeln('The server is not running');
                } else {
                    Output::writeln('The server is running');
                }
                break;
            default:
                Output::warning('Unsupported operation');
                return;
        }
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException
     */
    private function start(): void
    {
        /*** @compatible:Windows */
        if (PHP_OS_FAMILY !== 'Windows') {
            onSignal(SIGINT, fn () => $this->stop());
            onSignal(SIGTERM, fn () => $this->stop());
            onSignal(SIGQUIT, fn () => $this->stop());
        }

        if (!file_exists($this->controlPipePath)) {
            /*** @compatible:Windows */
            if (PHP_OS_FAMILY === 'Windows') {
                touch($this->controlPipePath);
            } else {
                posix_mkfifo($this->controlPipePath, 0600);
            }
        }

        $zx7e          = new Zx7e();
        $controlStream = new Stream(fopen($this->controlPipePath, 'r+'));
        $controlStream->setBlocking(false);
        $controlStream->onReadable(function (Stream $controlStream) use ($zx7e) {
            $content = $controlStream->read(1024);
            foreach ($zx7e->decodeStream($content) as $command) {
                $command = json_decode($command, true);
                $action  = $command['action'];
                switch ($action) {
                    case 'stop':
                        $this->stop();
                        break;
                    case 'reload':
                        $this->manager->reload();
                        break;
                    case 'status':
                        break;
                }
            }
        });

        $listen  = Env::get('PRP_HTTP_LISTEN', 'http://127.0.0.1:8008');
        $count   = intval(Env::get('PRP_HTTP_COUNT', 4)) ?? 4;
        $sandbox = boolval(Env::get('PRP_SANDBOX', false));

        $this->manager->addWorker(new Worker($listen, $count, $sandbox));
        $this->manager->run();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/19 10:33
     * @return void
     */
    private function stop(): void
    {
        cancelAll();
        $this->manager->stop();
        if (file_exists($this->controlPipePath)) {
            unlink($this->controlPipePath);
        }
    }
}
