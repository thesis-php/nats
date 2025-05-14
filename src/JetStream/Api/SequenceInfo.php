<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class SequenceInfo
{
    /**
     * @param non-negative-int $consumerSeq
     * @param non-negative-int $streamSeq
     */
    public function __construct(
        public int $consumerSeq,
        public int $streamSeq,
        public ?\DateTimeImmutable $lastActive = null,
    ) {}
}
