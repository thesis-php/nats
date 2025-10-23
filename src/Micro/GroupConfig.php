<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class GroupConfig
{
    /**
     * @param non-empty-string $name
     * @param ?non-empty-string $queueGroup
     */
    public function __construct(
        public string $name,
        public ?string $queueGroup = null,
    ) {}
}
