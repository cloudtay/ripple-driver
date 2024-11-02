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

namespace Ripple\Driver\Workerman;

use Revolt\EventLoop;
use Ripple\Kernel;
use Ripple\Process;
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
        $cbId = delay($closure, $delay);
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
        $cbId = repeat(static fn () => $func(...$args), $interval);
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
