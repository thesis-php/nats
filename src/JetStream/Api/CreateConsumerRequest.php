<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<ConsumerInfo>
 */
final readonly class CreateConsumerRequest implements Request
{
    public const string ACTION_CREATE = 'create';
    public const string ACTION_UPDATE = 'update';

    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     * @param ?self::ACTION_* $action
     */
    public function __construct(
        private string $stream,
        private string $consumer,
        private ConsumerConfig $config,
        private ?string $action = null,
    ) {}

    public function endpoint(): string
    {
        $endpoint = "CONSUMER.CREATE.{$this->stream}.{$this->consumer}";
        if ($this->config->filterSubject !== null && $this->config->filterSubject !== '' && ($this->config->filterSubjects ?? []) === []) {
            $endpoint .= ".{$this->config->filterSubject}";
        }

        return $endpoint;
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function payload(): array
    {
        return [
            'stream_name' => $this->stream,
            'config' => $this->config,
            'action' => $this->action ?? '',
        ];
    }

    public function type(): string
    {
        return ConsumerInfo::class;
    }
}
