<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum DiscardPolicy: string
{
    case Old = 'old';
    case New = 'new';
}
