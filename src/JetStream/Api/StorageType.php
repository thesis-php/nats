<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum StorageType: string
{
    case File = 'file';
    case Memory = 'memory';
}
