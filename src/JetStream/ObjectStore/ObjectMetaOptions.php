<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
final readonly class ObjectMetaOptions
{
    public function __construct(
        public ?ObjectLink $link = null,
        public ?int $maxChunkSize = null,
    ) {}
}
