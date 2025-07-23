<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Thesis\Nats\JetStream\Api\StreamInfo;

/**
 * @api
 */
final readonly class ObjectStoreInfo
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public string $name,
        public StreamInfo $info,
    ) {}
}
