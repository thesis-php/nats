<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp;
use Revolt\EventLoop;
use Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class PingPongHandler
{
    private ?string $callbackId = null;

    /** @var non-negative-int */
    private int $pings = 0;

    private ?float $lastPingPongTime = null;

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

        $this->callbackId = EventLoop::repeat($interval, function () use ($interval, $maxPings): void {
            if (++$this->pings > $maxPings) {
                $this->forceStop();
            } elseif ((Amp\now() - (int) $this->lastPingPongTime) > $interval) {
                $this->lastPingPongTime = Amp\now();
                $this->connection->execute(Protocol\Ping::Frame);
            }
        });

        $this->connection->hooks()->onPing(function (): void {
            $this->lastPingPongTime = Amp\now();
            $this->connection->execute(Protocol\Pong::Frame);
        });

        $this->connection->hooks()->onPong(function (): void {
            $this->lastPingPongTime = Amp\now();
            $this->pings = max(0, $this->pings - 1);
        });

        $this->connection->hooks()->onClose($this->stop(...));
    }

    public function stop(): void
    {
        if ($this->callbackId !== null) {
            EventLoop::cancel($this->callbackId);
        }
    }

    private function forceStop(): void
    {
        $this->stop();
        $this->connection->close();
    }
}
