<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * The server is going to shut down (lame duck mode) and asks the clients to move to the other servers.
 *
 * @internal
 */
enum LameDuckModeEntered
{
    case Event;
}
