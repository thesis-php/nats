<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use Revolt\EventLoop;

/**
 * A scheduled callback of the event loop, cancelled explicitly or once the timer is no longer referenced,
 * so the owner keeps it as long as the callback must run.
 *
 * @internal
 */
final readonly class Timer
{
    /**
     * @param \Closure(): void $callback
     */
    public static function delay(float $seconds, \Closure $callback): self
    {
        return new self(EventLoop::delay($seconds, $callback));
    }

    /**
     * @param \Closure(): void $callback
     */
    public static function repeat(float $interval, \Closure $callback): self
    {
        return new self(EventLoop::repeat($interval, $callback));
    }

    /**
     * @param string $id the callback identifier of the event loop
     */
    private function __construct(
        private string $id,
    ) {}

    /**
     * Does nothing if the callback has already run or has been cancelled.
     */
    public function cancel(): void
    {
        EventLoop::cancel($this->id);
    }

    public function __destruct()
    {
        $this->cancel();
    }
}
