<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class PriorityGroupState
{
    public function __construct(
        public string $group,
        public ?string $pinnedClientId = null,
        public ?\DateTimeImmutable $pinnedTs = null,
    ) {}
}
