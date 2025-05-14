<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum AckPolicy: string
{
    case None = 'none';
    case All = 'all';
    case Explicit = 'explicit';
}
