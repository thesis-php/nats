<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<StreamPurged>
 */
final readonly class PurgeStreamRequest implements Request
{
    /**
     * @param non-empty-string $name
     * @param ?non-negative-int $sequence
     * @param ?non-negative-int $keep
     */
    public function __construct(
        private string $name,
        private ?int $sequence = null,
        private ?int $keep = null,
        private ?string $subject = null,
    ) {}

    public function endpoint(): string
    {
        return ApiMethod::PurgeStream->compile($this->name);
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function payload(): array
    {
        return array_filter(
            [
                'seq' => $this->sequence,
                'keep' => $this->keep,
                'subject' => $this->subject,
            ],
            static fn(mixed $value) => $value !== null,
        );
    }

    public function type(): string
    {
        return StreamPurged::class;
    }
}
