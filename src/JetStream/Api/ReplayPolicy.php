<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum ReplayPolicy: string
{
    case Original = 'original';
    case Instant = 'instant';
}
