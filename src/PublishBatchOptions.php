<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
final readonly class PublishBatchOptions
{
    public function __construct(
        public bool $ack = false,
        public bool $commit = false,
    ) {}
}
