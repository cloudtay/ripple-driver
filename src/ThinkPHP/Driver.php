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

namespace Ripple\Driver\ThinkPHP;

use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Kernel;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Serialization\Zx7e;
use Ripple\Worker\Manager;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Env;

use function app;
use function Co\cancelAll;
use function Co\onSignal;
use function Co\wait;
use function file_exists;
use function flock;
use function fopen;
use function intval;
use function json_decode;
use function mkdir;
use function posix_mkfifo;
use function root_path;
use function runtime_path;
use function shell_exec;
use function sprintf;
use function touch;
use function unlink;

use const LOCK_EX;
use const LOCK_NB;
use const PHP_BINARY;
use const PHP_OS_FAMILY;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

class Driver extends Command
{
    /*** @var Manager */
    private Manager $manager;

    /*** @var string */
    private string $controlPipePath;

    /*** @var string */
    private string $controlLockPath;

    /**
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function configure(): void
    {
        $this->setName('ripple')->setDescription('start the ripple service');
        $this->addArgument('action', Option::VALUE_REQUIRED, 'start|stop|reload|status', 'start');
        $this->addOption('daemon', 'd', Option::VALUE_NONE, 'Run the server on the background');
        $this->manager         = app(Manager::class);
        $this->controlPipePath = runtime_path() . '/ripple.pipe';
        $this->controlLockPath = runtime_path() . '/ripple.lock';
    }

    /**
     * @param Input  $input
     * @param Output $output
     *
     * @return void
     * @throws UnsupportedFeatureException
     * @throws ConnectionException
     */
    protected function execute(Input $input, Output $output): void
    {
        $zx7e = new Zx7e();
        switch ($input->getArgument('action')) {
            case 'start':
                if (!$input->getOption('daemon')) {
                    $this->start();
                    wait();
                } else {
                    if (!file_exists(runtime_path('log'))) {
                        mkdir(runtime_path('log'), 0755, true);
                    }
                    $command = sprintf(
                        '%s %s ripple:server start > %s &',
                        PHP_BINARY,
                        root_path() . 'think',
                        runtime_path('log') . 'ripple.log'
                    );
                    shell_exec($command);
                    \Ripple\Utils\Output::writeln('server started');
                }
                exit(0);
            case 'stop':
                if (!file_exists($this->controlPipePath)) {
                    \Ripple\Utils\Output::warning('The server is not running');
                    return;
                }
                $controlStream = new Stream(fopen($this->controlPipePath, 'r+'));
                $controlStream->write($zx7e->encodeFrame('{"action":"stop"}'));
                \Ripple\Utils\Output::writeln('The server is stopping');
                break;
            case 'reload':
                if (!file_exists($this->controlPipePath)) {
                    \Ripple\Utils\Output::warning('The server is not running');
                    return;
                }
                $controlStream = new Stream(fopen($this->controlPipePath, 'r+'));
                $controlStream->write($zx7e->encodeFrame('{"action":"reload"}'));
                \Ripple\Utils\Output::writeln('The server is reloading');
                break;
            case 'status':
                if (!file_exists($this->controlPipePath)) {
                    \Ripple\Utils\Output::writeln('The server is not running');
                } else {
                    \Ripple\Utils\Output::writeln('The server is running');
                }
                break;
            default:
                \Ripple\Utils\Output::warning('Unsupported operation');
        }
    }

    /**
     * @return void
     * @throws \Revolt\EventLoop\UnsupportedFeatureException
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
            if (!Kernel::getInstance()->supportProcessControl()) {
                touch($this->controlPipePath);
            } else {
                posix_mkfifo($this->controlPipePath, 0600);
            }
        }

        if (!file_exists($this->controlLockPath)) {
            touch($this->controlLockPath);
        }

        $controlStream = new Stream(fopen($this->controlPipePath, 'r+'));
        if (!flock(fopen($this->controlLockPath, 'r'), LOCK_EX | LOCK_NB)) {
            \Ripple\Utils\Output::warning('The server is already running');
            exit(0);
        }
        $controlStream->setBlocking(false);

        $zx7e = new Zx7e();
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

        $listen = Env::get('RIP_HTTP_LISTEN', 'http://127.0.0.1:8008');
        $count  = intval(Env::get('RIP_HTTP_WORKERS', 4)) ?? 4;
        $this->manager->addWorker(new Worker($listen, $count));
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

        if (file_exists($this->controlLockPath)) {
            unlink($this->controlLockPath);
        }
    }
}
