<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal\Heartbeat;

use Revolt\EventLoop;
use Thesis\Nats\Exception\NoHeartbeatsReceived;
use Thesis\Time\TimeSpan;

/**
 * @internal
 */
final class Watchdog
{
    private ?string $callbackId = null;

    private readonly float $interval;

    /** @var \Closure(): void */
    private readonly \Closure $func;

    /** @var list<\Closure(\Throwable): void> */
    private array $subscribers = [];

    private int $missed = 0;

    /**
     * @param positive-int $heartbeatsThreshold
     */
    public function __construct(
        ?TimeSpan $time,
        int $heartbeatsThreshold,
    ) {
        $missed = &$this->missed;
        $subscribers = &$this->subscribers;

        $this->interval = $time?->toSeconds(PHP_ROUND_HALF_DOWN) ?? 0;
        $this->func = static function () use (
            &$missed,
            &$subscribers,
            $heartbeatsThreshold,
        ): void {
            if (++$missed >= $heartbeatsThreshold) {
                foreach ($subscribers as $subscriber) {
                    $subscriber(new NoHeartbeatsReceived());
                }
            }
        };

        $this->schedule();
    }

    /**
     * @param \Closure(\Throwable): void $subscriber
     */
    public function subscribe(\Closure $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    public function reset(): void
    {
        $this->stop();
        $this->schedule();
    }

    public function stop(): void
    {
        $this->missed = 0;

        if ($this->callbackId !== null) {
            EventLoop::cancel($this->callbackId);
            $this->callbackId = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function schedule(): void
    {
        if ($this->interval > 0) {
            $this->callbackId = EventLoop::repeat($this->interval, $this->func);
        }
    }
}
