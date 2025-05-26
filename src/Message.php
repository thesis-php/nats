<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
final readonly class Message
{
    public function __construct(
        public ?string $payload = null,
        public ?Headers $headers = null,
    ) {}
}
