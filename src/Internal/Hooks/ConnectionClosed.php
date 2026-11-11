<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * The connection is closed for good: nothing will be received or sent over it anymore.
 *
 * @internal
 */
final readonly class ConnectionClosed
{
    /**
     * @param ?\Throwable $reason the server error or the failure that closed the connection, null for a regular close
     */
    public function __construct(
        public ?\Throwable $reason = null,
    ) {}
}
