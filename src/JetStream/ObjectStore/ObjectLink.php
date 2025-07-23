<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
final readonly class ObjectLink
{
    public function __construct(
        public string $bucket,
        public ?string $name = null,
    ) {}
}
