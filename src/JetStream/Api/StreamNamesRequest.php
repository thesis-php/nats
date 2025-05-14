<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements PaginatedRequest<StreamNamesCollection>
 */
final readonly class StreamNamesRequest implements PaginatedRequest
{
    /**
     * @param ?non-empty-string $subject
     * @param ?non-negative-int $offset
     */
    public function __construct(
        private ?string $subject = null,
        private ?int $offset = null,
    ) {}

    public function withOffset(int $offset): self
    {
        return new self(
            subject: $this->subject,
            offset: $offset,
        );
    }

    public function endpoint(): string
    {
        return 'STREAM.NAMES';
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function payload(): array
    {
        return array_filter(
            [
                'subject' => $this->subject,
                'offset' => $this->offset,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function type(): string
    {
        return StreamNamesCollection::class;
    }
}
