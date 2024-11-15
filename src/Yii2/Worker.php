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

namespace Ripple\Driver\Yii2;

use Ripple\Driver\Utils\Config;
use Ripple\Driver\Utils\Console;
use Ripple\Driver\Utils\Guard;
use Ripple\File\File;
use Ripple\Http\Server;
use Ripple\Http\Server\Request;
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
