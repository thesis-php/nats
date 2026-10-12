<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Nats\Internal\Validation\Validate;

/**
 * @api
 * @template-implements Request<GetMessageResponse>
 */
final readonly class GetMessageRequest implements Request, \JsonSerializable
{
    /**
     * @param non-empty-string $stream
     * @param ?non-negative-int $seq
     * @param ?list<non-empty-string> $multiLast
     */
    public function __construct(
        private string $stream,
        private ?int $seq = null,
        private ?string $lastBySubject = null,
        private ?string $nextBySubject = null,
        private ?array $multiLast = null,
    ) {
        Validate::name($this->stream);

        foreach ([$this->lastBySubject, $this->nextBySubject, ...$this->multiLast ?? []] as $subject) {
            if ($subject !== null) {
                Validate::subscribeSubject($subject);
            }
        }
    }

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
                'multi_last' => $this->multiLast,
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
