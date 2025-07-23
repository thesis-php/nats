<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Thesis\Nats\Client;
use Thesis\Nats\JetStream;

/**
 * @api
 */
final readonly class Store
{
    public function __construct(
        public string $name,
        private Client $nats,
        private JetStream $js,
        private JetStream\Stream $stream,
    ) {}
}
