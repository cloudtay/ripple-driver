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

use Ripple\Driver\Utils\Config;
use Ripple\Driver\Utils\Console;
use Ripple\Driver\Utils\Guard;
use Ripple\Http\Server;
use Ripple\Http\Server\Request;
use Ripple\Kernel;
use Ripple\Worker\Manager;
use think\App;
use think\facade\Env;
use think\response\File;
use think\Route;
use Throwable;

use function app;
use function cli_set_process_title;
use function Co\repeat;
use function file_exists;
use function fwrite;
use function gc_collect_cycles;
use function getmypid;
use function posix_getpid;
use function root_path;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

use const STDOUT;

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class Worker extends \Ripple\Worker\Worker
{
    use Console;

    /**
     * @var Server
     */
    private Server $server;

    /**
     * @param string $address
     * @param int    $count
     */
    public function __construct(
        private readonly string $address = 'http://127.0.0.1:8008',
        int                     $count = 4
    ) {
        $this->count = $count;
        $this->name  = 'http-server';
    }

    /**
     * 注册服务流程,监听端口,发生于主进程中
     *
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
        cli_set_process_title('think-guard');

        app()->bind(Worker::class, fn () => $this);

        $server = new Server($this->address, [
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1
            ]
        ]);

        $this->server = $server;

        fwrite(STDOUT, $this->formatRow(['Worker', $this->getName()]));
        fwrite(STDOUT, $this->formatRow(['Listen', $this->address]));
        fwrite(STDOUT, $this->formatRow(["Workers", $this->count]));
        fwrite(STDOUT, $this->formatRow(["- Logs"]));

        // 热重载监听文件改动
        if (Config::value2bool(Env::get('PHP_HOT_RELOAD'))) {
            $monitor = \Ripple\File\File::getInstance()->monitor();
            $monitor->add(root_path() . '/app');
            $monitor->add(root_path() . '/config');
            $monitor->add(root_path() . '/extend');
            $monitor->add(root_path() . '/route');
            $monitor->add(root_path() . '/view');
            if (file_exists(root_path() . '/.env')) {
                $monitor->add(root_path() . '/.env');
            }

            Guard::relevance($manager, $this, $monitor);
        }
    }

    /**
     * 子进程启动流程,发生于子进程中
     *
     * @Author cclilshy
     * @Date   2024/8/17 11:08
     * @return void
     */
    public function boot(): void
    {
        cli_set_process_title('think-worker');

        fwrite(STDOUT, sprintf(
            "Worker %s@%d started.\n",
            $this->getName(),
            Kernel::getInstance()->supportProcessControl() ? posix_getpid() : getmypid()
        ));

        /*** register loop timer*/
        repeat(static function () {
            gc_collect_cycles();
        }, 1);

        $app = new App();
        $app->bind(Worker::class, fn () => $this);

        $this->server->onRequest(static function (
            Request $request
        ) use ($app) {
            $response = $request->getResponse();

            // Construct tp request object based on ripple/request meta information
            $headers = [];
            foreach ($request->SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
                }
            }

            $thinkRequest = new \think\Request();
            $thinkRequest->setUrl($request->SERVER['REQUEST_URI']);
            $thinkRequest->setBaseUrl($request->SERVER['REQUEST_URI']);
            $thinkRequest->setHost($request->SERVER['HTTP_HOST'] ?? '');
            $thinkRequest->setPathinfo($request->SERVER['REQUEST_URI'] ?? '');
            $thinkRequest->setMethod($request->SERVER['REQUEST_METHOD'] ?? '');
            $thinkRequest->withHeader($headers);
            $thinkRequest->withCookie($request->COOKIE);
            $thinkRequest->withFiles($request->FILES);
            $thinkRequest->withGet($request->GET);
            $thinkRequest->withInput($request->CONTENT);

            # 交由think-app处理请求
            $thinkResponse = $app->http->run($thinkRequest);

            $response->setStatusCode($thinkResponse->getCode());
            foreach ($thinkResponse->getHeader() as $key => $value) {
                $response->withHeader($key, $value);
            }

            # 根据响应类型处理响应内容
            if ($thinkResponse instanceof File) {
                $response->setContent(\Ripple\File\File::open($thinkResponse->getData(), 'r+'));
            } else {
                $response->setContent($thinkResponse->getData());
            }
            $response->respond();
            $app->delete(Route::class);
        });
        $this->server->listen();
    }
}
