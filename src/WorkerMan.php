<?php

namespace Cclilshy\PRippleDrive;

use Cclilshy\PRippleEvent\Core\Stream\Stream;
use JetBrains\PhpStorm\NoReturn;
use Throwable;
use Workerman\Events\EventInterface;
use function A\cancel;
use function A\delay;
use function A\loop;
use function A\onReadable;
use function A\onSignal;
use function A\onWritable;
use function A\repeat;
use function count;

class WorkerMan implements EventInterface
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
                    $id                     = onSignal($fd, $func);
                    $this->_signal2ids[$fd] = $id;
                    return $id;
                } catch (Throwable $e) {
                    return false;
                }

            case EventInterface::EV_TIMER:
                $this->_timer[] = $timerId = repeat($fd, $func);
                return $timerId;

            case EventInterface::EV_TIMER_ONCE:
                $this->_timer[] = $timerId = delay($fd, $func);
                return $timerId;

            case EventInterface::EV_READ:
                $id                         = onReadable(new Stream($fd), function (Stream $stream) use ($func) {
                    $func($stream->stream);
                });
                $streamId                   = get_resource_id($fd);
                $this->_fd2ids[$streamId][] = $id;
                return $id;

            case EventInterface::EV_WRITE:
                $id                         = onWritable(new Stream($fd), function (Stream $stream) use ($func) {
                    $func($stream->stream);
                });
                $streamId                   = get_resource_id($fd);
                $this->_fd2ids[$streamId][] = $id;
                return $id;
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
        loop();
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
    #[NoReturn] public function destroy(): void
    {
        exit;
    }
}
