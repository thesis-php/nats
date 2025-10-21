<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Thesis\Nats\Headers;

/**
 * @api
 */
final readonly class Response
{
    public function __construct(
        public ?string $data = null,
        public Headers $headers = new Headers(),
    ) {}
}
