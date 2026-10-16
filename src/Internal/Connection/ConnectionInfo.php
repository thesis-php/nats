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
            supportJetstream: $info->jetstream ?? false,
            maxPayload: $info->maxPayload,
        );
    }

    /**
     * @param non-empty-string $serverVersion
     * @param ?positive-int $maxPayload the largest message (headers and payload) the server accepts, in bytes,
     *                                  null if the server does not announce it
     */
    public function __construct(
        public string $serverVersion,
        public bool $allowHeaders = false,
        public bool $supportJetstream = false,
        public ?int $maxPayload = null,
    ) {}
}
