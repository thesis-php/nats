<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class MessageDeleted
{
    public function __construct(
        public ?bool $success = null,
    ) {}
}
