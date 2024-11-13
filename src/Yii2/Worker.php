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

namespace Ripple\Driver\Yii2;

use Ripple\File\File;
use Ripple\Http\Server\Request;
use Ripple\Http\Server;
use Ripple\Driver\Utils\Config;
use Ripple\Driver\Utils\Console;
use Ripple\Driver\Utils\Guard;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;
use Throwable;
use Yii;
use yii\base\InvalidParamException;

use function cli_set_process_title;
use function define;
use function defined;
use function file_exists;
use function fwrite;

use const STDOUT;

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class Worker extends \Ripple\Worker\Worker
{
    use Console;

    /*** @var Server */
    private Server $server;

    /*** @var Application */
    private Application $application;

    /*** @var string */
    private string $rootPath;

    /**
     * @param string $address
     * @param int    $count
     * @param bool   $sandbox
     *
     */
    public function __construct(
        private readonly string $address = 'http://127.0.0.1:8008',
        int                     $count = 1,
        private readonly bool   $sandbox = true,
    ) {
        $this->name  = 'http-server';
        $this->count = $count;
        try {
            $this->rootPath = Yii::getAlias('@app');
        } catch (InvalidParamException) {
            Output::error('Yii2 root path not found');
            exit(1);
        }

        defined('YII_DEBUG') or define('YII_DEBUG', true);
        defined('YII_ENV') or define('YII_ENV', 'dev');
    }

    /**
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
        cli_set_process_title('yii2-guard');

        /*** output worker*/
        fwrite(STDOUT, $this->formatRow(['Worker', $this->getName()]));

        /*** output env*/
        fwrite(STDOUT, $this->formatRow(["- Conf"]));
        foreach (Driver::DECLARE_OPTIONS as $key => $type) {
            fwrite(STDOUT, $this->formatRow([
                $key,
                '',
            ]));
        }

        /*** output logs*/
        fwrite(STDOUT, $this->formatRow(["- Logs"]));

        $server = new Server($this->address, [
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1
            ]
        ]);

        $this->server      = $server;
        $config            = require $this->rootPath . '/config/web.php';
        $this->application = new Application($config);

        if (Config::value2bool(1)) {
            $monitor = File::getInstance()->monitor();
            $monitor->add($this->rootPath . ('/commands'));
            $monitor->add($this->rootPath . ('/controllers'));
            $monitor->add($this->rootPath . ('/mail'));
            $monitor->add($this->rootPath . ('/models'));
            $monitor->add($this->rootPath . ('/views'));
            $monitor->add($this->rootPath . ('/web'));
            $monitor->add($this->rootPath . ('/widgets'));
            $monitor->add($this->rootPath . ('/vagrant'));
            $monitor->add($this->rootPath . ('/mail'));
            $monitor->add($this->rootPath . ('/config'));
            if (file_exists($this->rootPath . ('/.env'))) {
                $monitor->add($this->rootPath . ('/.env'));
            }

            Guard::relevance($manager, $this, $monitor);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 11:08
     * @return void
     */
    public function boot(): void
    {
        $this->server->onRequest(function (Request $request) {
            $application = clone $this->application;
            $application->rippleDispatch($request);
        });
        $this->server->listen();
    }
}
