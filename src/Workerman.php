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

namespace Psc\Drive;

use Closure;
use P\System;
use Psc\Core\Output;
use Psc\Core\Stream\Stream;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use function call_user_func;
use function call_user_func_array;
use function count;
use function explode;
use function function_exists;
use function get_resource_id;
use function is_array;
use function is_string;
use function P\cancel;
use function P\delay;
use function P\onSignal;
use function P\repeat;
use function P\run;
use function posix_getpid;
use function str_contains;

class Workerman implements EventInterface
{
    /**
     * @var array
     */
    protected array $_timer      = array();
    protected array $_fd2ids     = array();
    protected array $_signal2ids = array();

    /**
     * @param       $fd
     * @param       $flag
     * @param       $func
     * @param array $args
     * @return bool|string|void
     */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case EventInterface::EV_SIGNAL:
                try {
                    if ($func instanceof Closure) {
                        $closure = fn () => $func($fd);
                    }

                    if (is_array($func)) {
                        $closure = fn () => call_user_func($func, $fd);
                    }

                    if (is_string($func)) {
                        if (str_contains($func, '::')) {
                            $explode = explode('::', $func);
                            $closure = fn () => call_user_func($explode, $fd);
                        }

                        if (function_exists($func)) {
                            $closure = fn () => $func($fd);
                        }
                    }

                    if (!isset($closure)) {
                        return false;
                    }
                    $this->_signal2ids[$fd] = $id = onSignal($fd, $closure);
                    return $id;
                } catch (Throwable) {
                    return false;
                }

            case EventInterface::EV_TIMER:
                $this->_timer[] = $timerId = repeat(function () use ($func, $args) {
                    try {
                        call_user_func_array($func, $args);
                    } catch (Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }, $fd);
                return $timerId;

            case EventInterface::EV_TIMER_ONCE:
                $this->_timer[] = $timerId = delay(function () use ($func, $args) {
                    try {
                        call_user_func_array($func, $args);
                    } catch (Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }, $fd);

                return $timerId;

            case EventInterface::EV_READ:
                $stream                       = new Stream($fd);
                $this->_fd2ids[$stream->id][] = $eventId = $stream->onReadable(function (Stream $stream) use ($func) {
                    $func($stream->stream);
                });
                return $eventId;

            case EventInterface::EV_WRITE:
                $stream                       = new Stream($fd);
                $this->_fd2ids[$stream->id][] = $eventId = $stream->onWritable(function (Stream $stream) use ($func) {
                    $func($stream->stream);
                });
                return $eventId;
        }
    }

    /**
     * @param $fd
     * @param $flag
     * @return void
     */
    public function del($fd, $flag): void
    {
        if ($flag === EventInterface::EV_TIMER || $flag === EventInterface::EV_TIMER_ONCE) {
            cancel($fd);
            return;
        }

        if ($flag === EventInterface::EV_READ || $flag === EventInterface::EV_WRITE) {
            $streamId = get_resource_id($fd);
            if (isset($this->_fd2ids[$streamId])) {
                foreach ($this->_fd2ids[$streamId] as $id) {
                    cancel($id);
                }
                unset($this->_fd2ids[$streamId]);
            }
            return;
        }

        if ($flag === EventInterface::EV_SIGNAL) {
            $signalId = $this->_signal2ids[$fd] ?? null;
            if ($signalId) {
                cancel($signalId);
                unset($this->_signal2ids[$fd]);
            }
        }
    }

    /**
     * @return void
     */
    public function clearAllTimer(): void
    {
        foreach ($this->_timer as $timerId) {
            cancel($timerId);
        }
    }

    /**
     * @return void
     */
    public function loop(): void
    {
        if (!isset(self::$baseProcessId)) {
            self::$baseProcessId = posix_getpid();
        } elseif (self::$baseProcessId !== posix_getpid()) {
            self::$baseProcessId = posix_getpid();
            try {
                System::Process()->noticeFork();
            } catch (UnsupportedFeatureException $e) {
                Output::error($e->getMessage());
            }
        }
        run();
    }

    /**
     * @return int
     */
    public function getTimerCount(): int
    {
        return count($this->_timer);
    }

    /**
     * @return void
     */
    public function destroy(): void
    {
        /**
         * @deprecated 兼容__destruct
         */
        // \P\tick();
    }

    /**
     * @var int
     */
    private static int $baseProcessId;
}
