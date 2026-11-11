<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Cancellation;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Protocol;
use Thesis\Nats\NatsException;
use Thesis\Nats\ServerInfo;

/**
 * @internal
 */
interface Connection
{
    /**
     * Writes the frames at once, in verbose mode then waits for the acknowledgements of all of them.
     *
     * @param Protocol\Frame|non-empty-list<Protocol\Frame> $frames
     * @throws NatsException
     */
    public function execute(Protocol\Frame|array $frames, ?Cancellation $cancellation = null): void;

    /**
     * Sends PING and waits for its PONG: the server has then processed everything written before it.
     *
     * @return float the round-trip time in seconds
     * @throws NatsException
     */
    public function ping(?Cancellation $cancellation = null): float;

    public Hooks\Provider $hooks { get; }

    /**
     * @throws NatsException
     */
    public function info(): ServerInfo;

    /**
     * @param ?\Throwable $reason why the client closes the connection, e.g. a stale one, null for a regular close
     */
    public function close(?\Throwable $reason = null): void;
}
