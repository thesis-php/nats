<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<ConsumerPaused>
 */
final readonly class PauseConsumerRequest implements Request
{
    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     */
    public function __construct(
        private string $stream,
        private string $consumer,
        private ?\DateTimeImmutable $pauseUntil = null,
    ) {}

    public function endpoint(): string
    {
        return ApiMethod::PauseConsumer->compile($this->stream, $this->consumer);
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function payload(): array
    {
        return array_filter(
            [
                'pause_until' => $this->pauseUntil?->format(\DateTimeInterface::RFC3339),
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function type(): string
    {
        return ConsumerPaused::class;
    }
}
