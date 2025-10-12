<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Counter;

/**
 * @api
 */
final readonly class Entry
{
    /**
     * @param non-empty-string $subject
     */
    public function __construct(
        public string $subject,
        public int $value,
        public int $incr,
    ) {}
}
