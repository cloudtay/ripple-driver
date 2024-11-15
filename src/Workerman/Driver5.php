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

namespace Ripple\Driver\Workerman;

use Revolt\EventLoop;
use Ripple\Kernel;
use Ripple\Process\Process;
use Workerman\Events\EventInterface;

use function array_shift;
use function Co\cancel;
use function Co\delay;
use function Co\repeat;
use function Co\stop;
use function Co\wait;
use function count;
use function getmypid;
use function pcntl_signal;
use function sleep;

use const SIG_IGN;
use const SIGINT;

final class Driver5 implements EventInterface
{
    /**
     * @var int|float
     */
    private static int|float $baseProcessId;

    /**
     * @var array
     */
    private array $readEvents = [];

    /**
     * @var array
     */
    private array $writeEvents = [];

    /**
     * @var array
     */
    private array $eventSignal = [];

    /**
     * @var array
     */
    private array $eventTimer = [];

    /**
     * @var int
     */
    private int $timerId = 1;

    /**
     * @return void
     */
    public function run(): void
    {
        if (!isset(Driver5::$baseProcessId)) {
            Driver5::$baseProcessId = (getmypid());
        } elseif (Driver5::$baseProcessId !== (getmypid())) {
            Driver5::$baseProcessId = (getmypid());
            Process::getInstance()->processedInMain(static function () {
                Process::getInstance()->forgetEvents();
            });
        }

        wait();

        while (1) {
            sleep(1);
        }
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        stop();
        if (Kernel::getInstance()->supportProcessControl()) {
            pcntl_signal(SIGINT, SIG_IGN);
        }
    }

    /**
     * @param float    $delay
     * @param callable $func
     * @param array    $args
     *
     * @return int
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId                    = $this->timerId++;
        $closure                    = function () use ($func, $args, $timerId) {
            unset($this->eventTimer[$timerId]);
            $func(...$args);
        };
        $cbId                       = delay($closure, $delay);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * @param float    $interval
     * @param callable $func
     * @param array    $args
     *
     * @return int
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId                    = $this->timerId++;
        $cbId                       = repeat(static fn () => $func(...$args), $interval);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * @param          $stream
     * @param callable $func
     *
     * @return void
     */
    public function onReadable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            cancel($this->readEvents[$fdKey]);
            unset($this->readEvents[$fdKey]);
        }

        $this->readEvents[$fdKey] = EventLoop::onReadable($stream, static fn () => $func($stream));
    }

    /**
     * @param $stream
     *
     * @return bool
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            cancel($this->readEvents[$fdKey]);
            unset($this->readEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * @param          $stream
     * @param callable $func
     *
     * @return void
     */
    public function onWritable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            cancel($this->writeEvents[$fdKey]);
            unset($this->writeEvents[$fdKey]);
        }
        $this->writeEvents[$fdKey] = EventLoop::onWritable($stream, static fn () => $func($stream));
    }

    /**
     * @param $stream
     *
     * @return bool
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            cancel($this->writeEvents[$fdKey]);
            unset($this->writeEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * @param int      $signal
     * @param callable $func
     *
     * @return void
     * @throws \Revolt\EventLoop\UnsupportedFeatureException
     */
    public function onSignal(int $signal, callable $func): void
    {
        $fdKey = $signal;
        if (isset($this->eventSignal[$fdKey])) {
            cancel($this->eventSignal[$fdKey]);
            unset($this->eventSignal[$fdKey]);
        }
        $this->eventSignal[$fdKey] = EventLoop::onSignal($signal, static fn () => $func($signal));
    }

    /**
     * @param int $signal
     *
     * @return bool
     */
    public function offSignal(int $signal): bool
    {
        $fdKey = $signal;
        if (isset($this->eventSignal[$fdKey])) {
            cancel($this->eventSignal[$fdKey]);
            unset($this->eventSignal[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * @param int $timerId
     *
     * @return bool
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * @param int $timerId
     *
     * @return bool
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            cancel($this->eventTimer[$timerId]);
            unset($this->eventTimer[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * @return void
     */
    public function deleteAllTimer(): void
    {
        while ($cbId = array_shift($this->eventTimer)) {
            cancel($cbId);
        }
    }

    /**
     * @return int
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * @param callable $errorHandler
     *
     * @return void
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        EventLoop::setErrorHandler($errorHandler);
    }
}
