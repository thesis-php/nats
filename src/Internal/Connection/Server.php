<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

/**
 * A server of the pool and its reconnect statistics.
 *
 * @internal
 */
final class Server
{
    /** @var non-negative-int the reconnect attempts since the client was last connected to this server */
    public private(set) int $reconnects = 0;

    /** @var ?class-string<\Throwable> the error the last attempt failed with */
    public private(set) ?string $lastError = null;

    /**
     * @param non-empty-string $url `host:port`
     * @param bool $implicit whether the server was announced by the cluster rather than configured
     */
    public function __construct(
        public readonly string $url,
        public readonly bool $implicit,
    ) {}

    /**
     * @return bool whether the attempt failed with the same kind of error as the previous one
     */
    public function failed(\Throwable $e): bool
    {
        ++$this->reconnects;
        $repeated = $this->lastError === $e::class;
        $this->lastError = $e::class;

        return $repeated;
    }

    public function connected(): void
    {
        $this->reconnects = 0;
        $this->lastError = null;
    }
}
