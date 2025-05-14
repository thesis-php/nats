<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class ConsumerPaused
{
    public function __construct(
        public bool $paused,
        public \DateTimeImmutable $pauseUntil,
        public ?TimeSpan $pauseRemaining = null,
    ) {}
}
