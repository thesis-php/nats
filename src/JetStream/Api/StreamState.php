<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class StreamState
{
    /**
     * @param non-negative-int $messages
     * @param non-negative-int $bytes
     * @param non-negative-int $firstSeq
     * @param non-negative-int $lastSeq
     * @param list<non-negative-int> $deleted
     * @param ?non-negative-int $numSubjects
     * @param array<string, non-negative-int> $subjects
     */
    public function __construct(
        public int $messages,
        public int $bytes,
        public int $firstSeq,
        public \DateTimeImmutable $firstTs,
        public int $lastSeq,
        public \DateTimeImmutable $lastTs,
        public int $consumerCount,
        public array $deleted = [],
        public ?int $numDeleted = null,
        public ?int $numSubjects = null,
        public array $subjects = [],
    ) {}
}
