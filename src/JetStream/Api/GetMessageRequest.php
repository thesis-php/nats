<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<GetMessageResponse>
 */
final readonly class GetMessageRequest implements Request, \JsonSerializable
{
    /**
     * @param non-empty-string $stream
     * @param ?non-negative-int $seq
     */
    public function __construct(
        private string $stream,
        private ?int $seq = null,
        private ?string $lastBySubject = null,
        private ?string $nextBySubject = null,
    ) {}

    public function endpoint(): string
    {
        return ApiMethod::StreamMsgGet->compile($this->stream);
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function payload(): array
    {
        return array_filter(
            [
                'seq' => $this->seq,
                'last_by_subj' => $this->lastBySubject,
                'next_by_subj' => $this->nextBySubject,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function type(): string
    {
        return GetMessageResponse::class;
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload();
    }
}
