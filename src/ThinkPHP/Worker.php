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

namespace Psc\Drive\ThinkPHP;

use Co\IO;
use Co\Net;
use JetBrains\PhpStorm\NoReturn;
use Psc\Core\Http\Server\HttpServer;
use Psc\Core\Http\Server\Request;
use Psc\Core\Http\Server\Response;
use Psc\Drive\Utils\Console;
use Psc\Utils\Output;
use Psc\Worker\Command;
use Psc\Worker\Manager;
use think\App;
use think\facade\Env;
use think\response\File;
use think\Route;
use Throwable;

use function app;
use function cli_set_process_title;
use function Co\cancelAll;
use function Co\repeat;
use function file_exists;
use function fwrite;
use function getmypid;
use function in_array;
use function posix_getpid;
use function root_path;
use function sprintf;
use function stream_context_create;
use function gc_collect_cycles;

use const PHP_OS_FAMILY;
use const STDOUT;

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class Worker extends \Psc\Worker\Worker
{
    use Console;

    /**
     * @param string $address
     * @param int    $count
     */
    public function __construct(
        private readonly string $address = 'http://127.0.0.1:8008',
        private readonly int    $count = 4
    ) {
    }

    /**
     * @var HttpServer
     */
    private HttpServer $httpServer;

    /**
     * @Author cclilshy
     * @Date   2024/8/16 23:34
     * @param Manager $manager
     * @return void
     * @throws Throwable
     */
    public function register(Manager $manager): void
    {
        cli_set_process_title('think-guard');
        app()->bind(Worker::class, fn () => $this);

        $context          = stream_context_create(['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
        $this->httpServer = Net::Http()->server($this->address, $context);
        fwrite(STDOUT, $this->formatRow(['Worker', $this->getName()]));
        fwrite(STDOUT, $this->formatRow(['Listen', $this->address]));
        fwrite(STDOUT, $this->formatRow(["Workers", $this->count]));
        fwrite(STDOUT, $this->formatRow(["- Logs"]));


        if (in_array(Env::get('PHP_HOT_RELOAD'), ['true', '1', 'on', true, 1], true)) {
            $monitor = IO::File()->watch();
            $monitor->add(root_path() . '/app');
            $monitor->add(root_path() . '/config');
            $monitor->add(root_path() . '/extend');
            $monitor->add(root_path() . '/route');
            $monitor->add(root_path() . '/view');
            if (file_exists(root_path() . '/.env')) {
                $monitor->add(root_path() . '/.env');
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
     * @Date   2024/8/17 11:08
     * @return void
     */
    public function boot(): void
    {
        fwrite(STDOUT, sprintf(
            "Worker %s@%d started.\n",
            $this->getName(),
            PHP_OS_FAMILY === 'Windows' ? getmypid() : posix_getpid()
        ));
        cli_set_process_title('think-worker');

        /**
         * register loop timer
         */
        repeat(static function () {
            gc_collect_cycles();
        }, 1);


        $app = new App();
        $app->bind(Worker::class, fn () => $this);

        $this->httpServer->onRequest(static function (
            Request  $request,
            Response $response
        ) use ($app) {

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

            $thinkResponse = $app->http->run($thinkRequest);
            $thinkRequest->delete(Route::class);

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
        });

        $this->httpServer->listen();
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
     * @param Command $workerCommand
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
