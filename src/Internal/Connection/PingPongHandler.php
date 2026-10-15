<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Thesis\Nats\Internal\Protocol;
use Thesis\Nats\Internal\Timer;

/**
 * @internal
 */
final class PingPongHandler
{
    private ?Timer $timer = null;

    /** @var non-negative-int */
    private int $pings = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @param positive-int $interval
     * @param positive-int $maxPings
     */
    public function startup(int $interval, int $maxPings): void
    {
        $interval /= 1_000;

        $this->timer = Timer::repeat($interval, function () use ($maxPings): void {
            if (++$this->pings > $maxPings) {
                $this->forceStop();

                return;
            }

            $this->connection->execute(Protocol\Ping::Frame);
        });

        $this->connection->hooks->onPing(function (): void {
            $this->connection->execute(Protocol\Pong::Frame);
        });

        $this->connection->hooks->onPong(function (): void {
            $this->pings = 0;
        });

        $this->connection->hooks->onClose($this->stop(...));
    }

    public function stop(): void
    {
        $this->timer?->cancel();
    }

    private function forceStop(): void
    {
        $this->stop();
        $this->connection->close();
    }
}
