<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Thesis\Nats\Internal\Protocol\ServerInfo;

/**
 * @internal
 */
final class ConnectionInfo
{
    private bool $headers = false;

    private bool $jetstream = false;

    public function tune(ServerInfo $info): void
    {
        $this->headers = $info->headers;
        $this->jetstream = $info->jetstream ?: false;
    }

    public function allowHeaders(): bool
    {
        return $this->headers;
    }

    public function supportJetstream(): bool
    {
        return $this->jetstream;
    }
}
