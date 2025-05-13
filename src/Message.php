<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
final readonly class Message
{
    /**
     * @param array<non-empty-string, mixed> $headers
     */
    public function __construct(
        public ?string $payload = null,
        public array $headers = [],
        public ?Status $status = null,
    ) {}
}
