<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class StreamSource implements \JsonSerializable
{
    /**
     * @param non-empty-string $name
     * @param ?list<SubjectTransformConfig> $subjectTransforms
     */
    public function __construct(
        public string $name,
        public ?int $startSeq = null,
        public ?\DateTimeImmutable $startTime = null,
        public ?string $filterSubject = null,
        public ?array $subjectTransforms = null,
        public ?ExternalStream $external = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'name' => $this->name,
                'opt_start_seq' => $this->startSeq,
                'opt_start_time' => $this->startTime?->format(\DateTimeInterface::RFC3339),
                'filter_subject' => $this->filterSubject,
                'subject_transforms' => $this->subjectTransforms,
                'external' => $this->external,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
