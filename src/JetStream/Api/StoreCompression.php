<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum StoreCompression: string
{
    case None = 'none';
    case S2 = 's2';
}
