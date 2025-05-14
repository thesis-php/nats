<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<StreamNamesCollection>
 */
final readonly class StreamNamesRequest implements Request
{
    /**
     * @param ?non-empty-string $subject
     * @param ?non-negative-int $offset
     */
    public function __construct(
        public ?string $subject = null,
        public ?int $offset = null,
    ) {}

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
                'limit' => 1,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function type(): string
    {
        return StreamNamesCollection::class;
    }
}
