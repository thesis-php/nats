<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class EndpointConfig
{
    /**
     * @param ?non-empty-string $subject
     * @param ?non-empty-string $queueGroup
     * @param array<non-empty-string, string> $metadata
     */
    public function __construct(
        public ?string $subject = null,
        public ?string $queueGroup = null,
        public array $metadata = [],
    ) {}
}
