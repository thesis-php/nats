<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal\Heartbeat;

use Revolt\EventLoop;
use Thesis\Time\TimeSpan;
use function Amp\now;

/**
 * @internal
 */
final class Monitor
{
    private ?string $callbackId = null;

    private ?float $time = null;

    public function __construct(
        private readonly TimeSpan $interval,
    ) {}

    public function reset(): void
    {
        $this->time = now();
    }

    /**
     * @param \Closure(float): void $handler
     */
    public function monitor(\Closure $handler): void
    {
        $interval = $this->interval->toSeconds() * 2;

        $this->time = now();
        $this->callbackId ??= EventLoop::repeat($interval, function () use ($handler, $interval): void {
            $time = now() - $this->time;

            if ($time > $interval) {
                $handler($time);
            }
        });
    }

    public function stop(): void
    {
        if ($this->callbackId !== null) {
            EventLoop::cancel($this->callbackId);
            $this->callbackId = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
