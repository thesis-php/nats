<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class EndpointInfo
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $subject
     * @param ?non-empty-string $queueGroup
     * @param array<non-empty-string, string> $metadata
     */
    public function __construct(
        public string $name,
        public string $subject,
        public ?string $queueGroup = null,
        public array $metadata = [],
    ) {}
}
