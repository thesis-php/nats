<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

/**
 * @internal
 */
enum ConnectionState
{
    case Idle;
    case Alive;
    case Closed;
    case GracefulClosed;
}
