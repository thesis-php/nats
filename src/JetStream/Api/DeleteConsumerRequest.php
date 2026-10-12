<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Nats\Internal\Validation\Validate;

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
    ) {
        Validate::name($this->stream);
        Validate::name($this->consumer);
    }

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
