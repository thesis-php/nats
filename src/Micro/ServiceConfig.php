<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class ServiceConfig
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $version
     * @param array<non-empty-string, string> $metadata
     * @param ?non-empty-string $queueGroup
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description = '',
        public array $metadata = [],
        public ?string $queueGroup = null,
    ) {}
}
