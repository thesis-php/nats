<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
enum Pong implements Frame
{
    case Frame;

    public function encode(): string
    {
        return "PONG\r\n";
    }
}
