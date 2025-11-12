<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal\Heartbeat;

use Revolt\EventLoop;
use Thesis\Nats\Exception\NoHeartbeatsReceived;
use Thesis\Nats\Subscription;
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

    private int $missed = 0;

    /**
     * @param positive-int $heartbeatsThreshold
     */
    public function __construct(
        TimeSpan $time,
        Subscription $subscription,
        int $heartbeatsThreshold,
    ) {
        $subscription = $subscription->onComplete($this->stop(...));
        $missed = &$this->missed;

        $this->interval = $time->toSeconds();
        $this->func = static function () use (
            $subscription,
            $heartbeatsThreshold,
            &$missed,
        ): void {
            if (++$missed >= $heartbeatsThreshold) {
                $subscription->error(new NoHeartbeatsReceived());
            }
        };

        $this->schedule();
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
        $this->callbackId = EventLoop::repeat($this->interval, $this->func);
    }
}
