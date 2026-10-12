<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Nats\Internal\Validation\Validate;

/**
 * @api
 * @template-implements Request<MessageDeleted>
 */
final readonly class DeleteMessageRequest implements Request
{
    /**
     * @param non-empty-string $stream
     * @param non-negative-int $seq
     */
    public function __construct(
        private string $stream,
        private int $seq,
        private ?bool $noErase = null,
    ) {
        Validate::name($this->stream);
    }

    public function endpoint(): string
    {
        return ApiMethod::StreamMsgDelete->compile($this->stream);
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function payload(): array
    {
        return array_filter(
            [
                'seq' => $this->seq,
                'no_erase' => $this->noErase,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function type(): string
    {
        return MessageDeleted::class;
    }
}
