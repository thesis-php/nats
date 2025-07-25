<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
enum Ping implements Frame
{
    case Frame;

    public function encode(): string
    {
        return "PING\r\n";
    }
}
