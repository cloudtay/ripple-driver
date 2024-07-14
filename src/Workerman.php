<?php declare(strict_types=1);

namespace Psc\Drive;

use Closure;
use Psc\Core\Stream\Stream;
use Revolt\EventLoop;
use Throwable;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use function call_user_func_array;
use function count;
use function get_resource_id;
use function P\cancel;
use function P\delay;
use function P\onSignal;
use function P\repeat;
use function P\run;

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
                        $closure = fn() => $func($fd);
                    }

                    if (is_array($func)) {
                        $closure = fn() => call_user_func($func, $fd);
                    }

                    if (is_string($func)) {
                        if (str_contains($func, '::')) {
                            $explode = explode('::', $func);
                            $closure = fn() => call_user_func($explode, $fd);
                        }

                        if (function_exists($func)) {
                            $closure = fn() => $func($fd);
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
     *
     */
    public function __construct()
    {
        EventLoop::setDriver((new EventLoop\DriverFactory())->create());
    }
}
