<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class GetMessageResponse
{
    public function __construct(
        public StoredMessage $message,
    ) {}
}
