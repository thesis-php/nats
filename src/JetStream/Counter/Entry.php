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
     * @param array<non-empty-string, array<non-empty-string, int>> $sources
     */
    public function __construct(
        public string $subject,
        public int $value,
        public int $incr,
        public array $sources = [],
    ) {}
}
