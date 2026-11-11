<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
enum ConnectionStatus
{
    /** Not connected: never yet, or the connection has been closed; the next call that needs the server connects. */
    case Disconnected;

    /** The first connection is being established. */
    case Connecting;
    case Connected;

    /** The connection is lost: the client is trying the servers, the subscriptions are kept and the publishes buffered. */
    case Reconnecting;

    /** {@see Client::drain()} is completing the subscriptions before closing the connection. */
    case Draining;
}
