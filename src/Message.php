<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
final class Message
{
    /**
     * @param array<non-empty-string, mixed> $headers
     */
    public function __construct(
        public readonly ?string $payload = null,
        public readonly array $headers = [],
        public readonly ?Status $status = null,
    ) {}
}
