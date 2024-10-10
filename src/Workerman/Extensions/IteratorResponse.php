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

namespace Psc\Drive\Workerman\Extensions;

use Closure;
use Iterator;
use Psc\Drive\Workerman\Driver;
use Psc\Drive\Workerman\Driver4;
use Psc\Drive\Workerman\Driver5;
use Psc\Drive\Workerman\PDrive;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

use function array_merge;
use function version_compare;

/**
 * @Description
 */
class IteratorResponse extends Response
{
    /*** @var \Iterator|mixed */
    protected Iterator $iterator;

    /**
     * @param Iterator|Closure $iterator      迭代器
     * @param TcpConnection    $tcpConnection TCP连接
     * @param bool             $autopilot     自动驾驶
     */
    public function __construct(
        Iterator|Closure                 $iterator,
        protected readonly TcpConnection $tcpConnection,
        protected readonly bool          $autopilot = true,
        protected readonly bool          $closeWhenFinish = false,
    ) {
        if ($iterator instanceof Closure) {
            $iterator = $iterator();
        }

        $this->iterator = $iterator;

        if ($autopilot) {
            switch (Worker::$eventLoopClass) {
                case Driver5::class:
                case Driver4::class:
                case Driver::class:
                case PDrive::class:
                    \Co\defer(fn () => $this->processIterator());
                    break;
                default:
                    if (version_compare(Worker::VERSION, '5.0.0', '>=')) {
                        Worker::$globalEvent->delay(0, function () use ($iterator) {
                            $this->processIterator();
                        });
                    } else {
                        Timer::add(0.1, function () use ($iterator) {
                            $this->processIterator();
                        }, [], false);
                    }
                    break;
            }
        }

        parent::__construct(200, array_merge([
        ], []));
    }

    /**
     * @return $this
     */
    public function processIterator(): static
    {
        foreach ($this->iterator as $frame) {
            $this->tcpConnection->send($frame, true);
        }

        if ($this->closeWhenFinish) {
            $this->close();
        }

        return $this;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        $this->tcpConnection->close();
    }

    /**
     * @param Iterator|Closure $iterator
     * @param TcpConnection    $tcpConnection
     * @param bool             $closeWhenFinish
     * @param bool             $autopilot
     *
     * @return IteratorResponse
     */
    public static function create(
        Iterator|Closure $iterator,
        TcpConnection    $tcpConnection,
        bool             $closeWhenFinish = false,
        bool             $autopilot = true,
    ): IteratorResponse {
        return new static($iterator, $tcpConnection, $closeWhenFinish, $autopilot);
    }
}
