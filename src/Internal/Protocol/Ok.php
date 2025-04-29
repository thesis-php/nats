<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
enum Ok implements Frame
{
    case Frame;

    public function encode(): string
    {
        return "+OK\r\n";
    }
}
