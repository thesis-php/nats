<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class StreamSourceInfo
{
    /**
     * @param non-negative-int $lag
     * @param list<SubjectTransformConfig> $subjectTransforms
     */
    public function __construct(
        public string $name,
        public int $lag,
        public TimeSpan $active,
        public ?string $filterSubject = null,
        public array $subjectTransforms = [],
    ) {}
}
