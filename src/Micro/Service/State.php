<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Service;

use Thesis\Nats\Micro\Service;

/**
 * @api
 */
enum State
{
    /** The service handles requests. */
    case Alive;

    /** The service rejects new endpoints while its subscriptions are stopping or draining. */
    case Stopping;

    /** The service has stopped: by {@see Service::stop()}, {@see Service::drain()} or from the outside. */
    case Stopped;
}
