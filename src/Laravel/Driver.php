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

namespace Ripple\Driver\Laravel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Utils\Output;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function base_path;
use function Co\channel;
use function Co\lock;
use function Co\onSignal;
use function Co\proc;
use function Co\wait;
use function config_path;
use function file_exists;
use function file_get_contents;
use function fwrite;
use function putenv;
use function shell_exec;
use function sprintf;
use function storage_path;
use function str_replace;

use const PHP_BINARY;
use const PHP_OS_FAMILY;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;
use const STDOUT;

/**
 * @Author cclilshy
 * @Date   2024/8/17 16:16
 */
class Driver extends Command
{
    public const DECLARE_OPTIONS = [
        'HTTP_LISTEN'    => 'string',
        'HTTP_WORKERS'   => 'int',
        'HTTP_RELOAD'    => 'bool',
        'HTTP_ISOLATION' => 'bool',
    ];

    /**
     * the name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ripple:server
    {action=start : the action to perform ,Support start|stop|reload|status}
    {--d|daemon : Run the server in the background}';

    /**
     * the console command description.
     *
     * @var string
     */
    protected $description = 'start the ripple service';

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     */
    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
    }

    /**
     * 运行服务
     *
     * @return void
     * @throws \Revolt\EventLoop\UnsupportedFeatureException
     */
    public function handle(): void
    {
        if (!file_exists(config_path('ripple.php'))) {
            Output::warning('Please execute the following command to publish the configuration files first.');
            Output::writeln('php artisan vendor:publish --tag=ripple-config');
            return;
        }

        $projectPath = base_path();
        $lock        = lock($projectPath);

        switch ($this->argument('action')) {
            case 'start':
                if (!$lock->exclusion(false)) {
                    Output::warning('the server is already running');
                    return;
                }
                $lock->unlock();

                if (!$this->option('daemon')) {
                    $this->start();
                    wait();
                } else {
                    $command = sprintf(
                        '%s %s ripple:server start > %s &',
                        PHP_BINARY,
                        base_path('artisan'),
                        storage_path('logs/ripple.log')
                    );
                    shell_exec($command);
                    Output::writeln('server started');
                }
                exit(0);
            case 'stop':
                if ($lock->exclusion(false)) {
                    Output::warning('the server is not running');
                    return;
                }
                $channel = channel($projectPath);
                $channel->send('stop');
                break;

            case 'reload':
                if ($lock->exclusion(false)) {
                    Output::warning('the server is not running');
                    return;
                }
                $channel = channel($projectPath);
                $channel->send('reload');
                Output::info('the server is reloading');
                break;

            case 'status':
                if ($lock->exclusion(false)) {
                    Output::writeln('the server is not running');
                } else {
                    Output::info('the server is running');
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
            onSignal(SIGINT, static fn () => exit(0));
            onSignal(SIGTERM, static fn () => exit(0));
            onSignal(SIGQUIT, static fn () => exit(0));
        }

        foreach (['RIP_HTTP_LISTEN', 'RIP_HTTP_WORKERS', 'RIP_HTTP_RELOAD',] as $key) {
            $configKey   = str_replace('RIP_', 'ripple.', $key);
            $configValue = Config::get($configKey);
            putenv("{$key}={$configValue}");
        }
        putenv('RIP_PROJECT_PATH=' . base_path());

        $session                 = proc(PHP_BINARY);
        $session->onMessage      = static function (string $data) {
            fwrite(STDOUT, $data);
        };
        $session->onErrorMessage = static function (string $data) {
            Output::warning($data);
        };
        $session->write(file_get_contents(__DIR__ . '/Server.php'));
        $session->inputEot();
        $session->onClose = static fn () => exit(0);
        wait();
    }
}
