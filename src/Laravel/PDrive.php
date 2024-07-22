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
use Psc\Core\Output;
use Psc\Core\Stream\Stream;
use Psc\Library\System\Proc\Session;
use Psc\Std\Stream\Exception\ConnectionException;
use Revolt\EventLoop\UnsupportedFeatureException;

use function base_path;
use function cli_set_process_title;
use function file_exists;
use function fopen;
use function P\delay;
use function P\onSignal;
use function P\repeat;
use function P\run;
use function putenv;
use function sprintf;
use function unlink;

use const PHP_BINARY;
use const PHP_EOL;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

/**
 * Class HttpService
 */
class PDrive extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'p:run
    {--s|listen=http://127.0.0.1:8008}
    {--t|threads=4}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'start the P-Drive service';

    /**
     * @return void
     */
    private Stream $guildOutputStream;
    private string $guildOutput;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        cli_set_process_title('Laravel-PD');
        $appPath = base_path();

        $guildFile         = __DIR__ . '/Guide.php';
        $this->guildOutput = "{$guildFile}.lock";
        $listen            = $this->option('listen');
        $threads           = $this->option('threads');

        putenv("P_RIPPLE_APP_PATH=$appPath");
        putenv("P_RIPPLE_LISTEN=$listen");
        putenv("P_RIPPLE_THREADS=$threads");

        if (file_exists($this->guildOutput)) {
            Output::warning('The service is already running.');
            exit(0);
        }

        $this->session                 = System::Proc()->open();
        $this->session->onMessage      = (function ($message) {
            Output::info($message);
        });
        $this->session->onErrorMessage = (function ($message) {
            Output::warning($message);
        });

        $this->session->input(sprintf("%s %s\n", PHP_BINARY, __DIR__ . '/Guide.php'));

        repeat(function ($cancel) {
            if (file_exists($this->guildOutput)) {
                $cancel();

                $this->guildOutputStream = new Stream(fopen($this->guildOutput, 'r+'));
                $this->guildOutputStream->setBlocking(false);
                $this->guildOutputStream->onReadable(function (Stream $stream) {
                    echo $stream->read(8192);
                });

                $this->registerSignalHandler();
            }
        }, 0.1);

        run();
    }

    /**
     * @var Session
     */
    private Session $session;

    /**
     * @return void
     * @throws UnsupportedFeatureException
     */
    private function registerSignalHandler(): void
    {
        onSignal(SIGINT, fn () => $this->onQuitSignal());
        onSignal(SIGTERM, fn () => $this->onQuitSignal());
        onSignal(SIGQUIT, fn () => $this->onQuitSignal());
    }

    /**
     * @return void
     * @throws ConnectionException
     */
    #[NoReturn] private function onQuitSignal(): void
    {
        $this->guildOutputStream->write(PHP_EOL);
        $this->guildOutputStream->close();

        $this->session->inputSignal(SIGTERM);
        $this->session->close();

        delay(function () {
            unlink($this->guildOutput);
            exit(0);
        }, 1);
    }
}
