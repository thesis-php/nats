<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
enum Pong implements Frame
{
    case Frame;
}
