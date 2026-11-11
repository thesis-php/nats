<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * The client has reconnected: the subscriptions are restored and the buffered commands are sent.
 *
 * @internal
 */
enum Reconnected
{
    case Event;
}
