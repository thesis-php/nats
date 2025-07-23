<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

/**
 * @api
 */
final readonly class WatchConfig
{
    public function __construct(
        public bool $headersOnly = false,
        public bool $ignoreDeletes = false,
    ) {}
}
