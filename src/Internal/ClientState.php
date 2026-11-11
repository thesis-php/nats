<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use Thesis\Nats\Client;

/**
 * What the user has asked the client to do with its connection.
 *
 * @internal
 */
enum ClientState
{
    case Running;

    /** {@see Client::drain()} is completing the subscriptions before closing the connection. */
    case Draining;
}
