<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<ConsumerDeleted>
 */
final readonly class DeleteConsumerRequest implements Request
{
    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     */
    public function __construct(
        private string $stream,
        private string $consumer,
    ) {}

    public function endpoint(): string
    {
        return ApiMethod::DeleteConsumer->compile($this->stream, $this->consumer);
    }

    public function payload(): null
    {
        return null;
    }

    public function type(): string
    {
        return ConsumerDeleted::class;
    }
}
