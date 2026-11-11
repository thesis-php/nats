<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

use Thesis\Nats\Internal\Protocol;

/**
 * An INFO the server sent after the handshake: a change of the cluster topology or the lame duck mode notification.
 *
 * @internal
 */
final readonly class InfoReceived
{
    public function __construct(
        public Protocol\ServerInfo $info,
    ) {}
}
