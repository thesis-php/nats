<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

/**
 * @internal
 */
enum ReconnectState
{
    /** The first connection is not established yet. */
    case Idle;
    case Connected;

    /** The connection is lost, the client is trying the servers of the pool. */
    case Reconnecting;

    /** Closed by the user, by a fatal server error, or once every server has used up its reconnects. */
    case Closed;
}
