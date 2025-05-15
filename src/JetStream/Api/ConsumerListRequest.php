<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements PaginatedRequest<ConsumerInfoCollection>
 */
final readonly class ConsumerListRequest implements PaginatedRequest
{
    /**
     * @param non-empty-string $stream
     * @param ?non-negative-int $offset
     */
    public function __construct(
        private string $stream,
        private ?int $offset = null,
    ) {}

    public function withOffset(int $offset): self
    {
        return new self(
            stream: $this->stream,
            offset: $offset,
        );
    }

    public function endpoint(): string
    {
        return ApiMethod::ConsumerList->compile($this->stream);
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function payload(): array
    {
        return array_filter(
            [
                'offset' => $this->offset,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function type(): string
    {
        return ConsumerInfoCollection::class;
    }
}
