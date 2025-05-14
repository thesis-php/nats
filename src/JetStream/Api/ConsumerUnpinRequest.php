<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<EmptyResponse>
 */
final readonly class ConsumerUnpinRequest implements Request
{
    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     * @param non-empty-string $group
     */
    public function __construct(
        private string $stream,
        private string $consumer,
        private string $group,
    ) {}

    public function endpoint(): string
    {
        return "CONSUMER.UNPIN.{$this->stream}.{$this->consumer}";
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function payload(): array
    {
        return ['group' => $this->group];
    }

    public function type(): string
    {
        return EmptyResponse::class;
    }
}
