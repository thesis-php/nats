<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * The connection to the server is lost and the client is reconnecting.
 *
 * @internal
 */
final readonly class Disconnected
{
    /**
     * @param ?\Throwable $reason the server error or the failure that broke the connection, null if the server closed it
     */
    public function __construct(
        public ?\Throwable $reason = null,
    ) {}
}
