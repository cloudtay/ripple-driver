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

namespace Psc\Drive\Workerman;

use Closure;
use Co\System;
use Psc\Core\Stream\Stream;
use Psc\Kernel;
use Psc\Utils\Output;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;
use Workerman\Events\EventInterface;
use Workerman\Worker;

use function array_search;
use function call_user_func;
use function call_user_func_array;
use function Co\cancel;
use function Co\cancelAll;
use function Co\delay;
use function Co\onSignal;
use function Co\repeat;
use function Co\wait;
use function count;
use function explode;
use function function_exists;
use function get_resource_id;
use function getmypid;
use function int2string;
use function is_array;
use function is_string;
use function posix_getpid;
use function sleep;
use function str_contains;
use function string2int;

class Driver4 implements EventInterface
{
    /*** @var int */
    private static int $baseProcessId;

    /*** @var array */
    protected array $_timer = [];

    /*** @var array */
    protected array $_fd2ids = [];

    /*** @var array */
    protected array $_signal2ids = [];

    /**
     * @param       $fd   //callback
     * @param       $flag //类型
     * @param       $func //回调
     * @param array $args //参数列表
     *
     * @return bool|int
     */
    public function add($fd, $flag, $func, $args = []): bool|int
    {
        switch ($flag) {
            case EventInterface::EV_SIGNAL:
                try {
                    // 兼容 Workerman 的信号处理
                    if ($func instanceof Closure) {
                        $closure = fn () => $func($fd);
                    }

                    // 兼容 Workerman 数组Callback方式
                    if (is_array($func)) {
                        $closure = fn () => call_user_func($func, $fd);
                    }

                    // 兼容 Workerman 字符串Callback方式
                    if (is_string($func)) {
                        if (str_contains($func, '::')) {
                            $explode = explode('::', $func);
                            $closure = fn () => call_user_func($explode, $fd);
                        }

                        if (function_exists($func)) {
                            $closure = fn () => $func($fd);
                        }
                    }

                    // 未找到回调
                    if (!isset($closure)) {
                        return false;
                    }

                    // 注册信号处理器
                    $id                     = onSignal($fd, $closure);
                    $this->_signal2ids[$fd] = string2int($id);
                    return string2int($id);
                } catch (Throwable) {
                    return false;
                }

            case EventInterface::EV_TIMER:
                // 定时器
                $this->_timer[] = $timerId = repeat(function () use ($func, $args) {
                    try {
                        call_user_func_array($func, $args);
                    } catch (Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }, $fd);

                return string2int($timerId);

            case EventInterface::EV_TIMER_ONCE:
                // 一次性定时器
                $this->_timer[] = $timerId = delay(function () use ($func, $args) {
                    try {
                        call_user_func_array($func, $args);
                    } catch (Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }, $fd);

                return string2int($timerId);

            case EventInterface::EV_READ:
                // 读事件
                $stream  = new Stream($fd);
                $eventId = $stream->onReadable(function (Stream $stream) use ($func) {
                    $func($stream->stream);
                });

                $this->_fd2ids[$stream->id][] = string2int($eventId);
                return string2int($eventId);

            case EventInterface::EV_WRITE:
                // 写事件
                $stream  = new Stream($fd);
                $eventId = $stream->onWritable(function (Stream $stream) use ($func) {
                    $func($stream->stream);
                });

                $this->_fd2ids[$stream->id][] = string2int($eventId);
                return string2int($eventId);
        }
        return false;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/27 22:00
     *
     * @param $fd
     * @param $flag
     *
     * @return void
     */
    public function del($fd, $flag): void
    {
        if ($flag === EventInterface::EV_TIMER || $flag === EventInterface::EV_TIMER_ONCE) {
            // 取消定时器
            $this->cancel($fd);
            unset($this->_timer[array_search(int2string($fd), $this->_timer)]);
            return;
        }

        if ($flag === EventInterface::EV_READ || $flag === EventInterface::EV_WRITE) {
            // 取消读写事件监听
            $streamId = get_resource_id($fd);
            if (isset($this->_fd2ids[$streamId])) {
                foreach ($this->_fd2ids[$streamId] as $id) {
                    $this->cancel($id);
                }
                unset($this->_fd2ids[$streamId]);
            }
            return;
        }

        if ($flag === EventInterface::EV_SIGNAL) {
            // 取消信号监听
            $signalId = $this->_signal2ids[$fd] ?? null;
            if ($signalId) {
                $this->cancel($signalId);
                unset($this->_signal2ids[$fd]);
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/27 22:01
     *
     * @param int $id
     *
     * @return void
     */
    private function cancel(int $id): void
    {
        cancel(int2string($id));
    }

    /**
     * @return void
     */
    public function clearAllTimer(): void
    {
        // 清除所有定时器
        foreach ($this->_timer as $timerId) {
            $this->cancel($timerId);
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function loop(): void
    {
        if (!isset(Driver4::$baseProcessId)) {
            Driver4::$baseProcessId = (Kernel::getInstance()->supportProcessControl() ? getmypid() : posix_getpid());
        } elseif (Driver4::$baseProcessId !== (Kernel::getInstance()->supportProcessControl() ? getmypid() : posix_getpid())) {
            Driver4::$baseProcessId = (Kernel::getInstance()->supportProcessControl() ? getmypid() : posix_getpid());
            try {
                cancelAll();
                System::Process()->forkedTick();
            } catch (UnsupportedFeatureException $e) {
                Output::error($e->getMessage());
            }
        }
        wait();

        /**
         * 不会再有任何事发生
         *
         * Workerman会将结束的进程视为异常然后重启, 循环往复
         */
        while (1) {
            wait();
            sleep(1);
        }
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
        cancelAll();
    }
}
