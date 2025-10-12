<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum PersistMode: string
{
    case Default = 'Default';
    case Async = 'Async';
}
