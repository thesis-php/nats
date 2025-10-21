<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class Config
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $version
     * @param array<non-empty-string, string> $metadata
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description = '',
        public array $metadata = [],
        public string $queueGroup = '',
        public bool $queueGroupDisabled = false,
    ) {}
}
