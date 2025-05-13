<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Thesis\Nats\Internal\Protocol\ServerInfo;

/**
 * @internal
 */
final readonly class ConnectionInfo
{
    public static function fromServerInfo(ServerInfo $info): self
    {
        return new self(
            serverVersion: $info->version,
            allowHeaders: $info->headers,
            supportJetstream: $info->jetstream ?: false,
        );
    }

    /**
     * @param non-empty-string $serverVersion
     */
    public function __construct(
        public string $serverVersion,
        public bool $allowHeaders = false,
        public bool $supportJetstream = false,
    ) {}
}
