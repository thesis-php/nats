<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class ServiceIdentity
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $id
     * @param non-empty-string $version
     * @param array<non-empty-string, string> $metadata
     */
    public function __construct(
        public string $name,
        public string $id,
        public string $version,
        public array $metadata = [],
    ) {}
}
