<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum PriorityPolicy: string
{
    case None = '';
    case PinnedClient = 'pinned_client';
    case Overflow = 'overflow';
}
