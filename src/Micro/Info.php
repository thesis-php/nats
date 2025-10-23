<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class Info
{
    /**
     * @param list<EndpointInfo> $endpoints
     */
    public function __construct(
        public ServiceIdentity $identity,
        public string $description = '',
        public array $endpoints = [],
    ) {}
}
