<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class Stats
{
    /**
     * @param list<EndpointStats> $endpoints
     */
    public function __construct(
        public ServiceIdentity $identity,
        public \DateTimeImmutable $started,
        public array $endpoints = [],
    ) {}
}
