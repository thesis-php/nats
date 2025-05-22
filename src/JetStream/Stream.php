<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Thesis\Nats\JetStream;
use Thesis\Nats\NatsException;

/**
 * @api
 */
final readonly class Stream
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public Api\StreamInfo $info,
        public string $name,
        private JetStream $js,
    ) {}

    /**
     * @throws NatsException
     */
    public function delete(): Api\StreamDeleted
    {
        return $this->js->deleteStream($this->name);
    }

    /**
     * @param ?non-negative-int $sequence
     * @param ?non-negative-int $keep
     * @throws NatsException
     */
    public function purge(
        ?int $sequence = null,
        ?int $keep = null,
        ?string $subject = null,
    ): Api\StreamPurged {
        return $this->js->purgeStream(
            name: $this->name,
            sequence: $sequence,
            keep: $keep,
            subject: $subject,
        );
    }

    /**
     * @throws NatsException
     */
    public function createConsumer(Api\ConsumerConfig $config = new Api\ConsumerConfig()): Consumer
    {
        return $this->js->createConsumer($this->name, $config);
    }

    /**
     * @throws NatsException
     */
    public function createOrUpdateConsumer(Api\ConsumerConfig $config = new Api\ConsumerConfig()): Consumer
    {
        return $this->js->createOrUpdateConsumer($this->name, $config);
    }

    /**
     * @param non-empty-string $consumer
     * @throws NatsException
     */
    public function deleteConsumer(string $consumer): Api\ConsumerDeleted
    {
        return $this->js->deleteConsumer($this->name, $consumer);
    }
}
