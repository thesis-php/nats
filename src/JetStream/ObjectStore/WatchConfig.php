<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
final readonly class WatchConfig
{
    public function __construct(
        public bool $withHistory = false,
        public bool $ignoreDeletes = false,
    ) {}
}
