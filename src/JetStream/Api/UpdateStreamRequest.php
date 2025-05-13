<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<StreamInfo>
 */
final readonly class UpdateStreamRequest implements Request
{
    public function __construct(
        public StreamConfig $config,
    ) {}

    public function endpoint(): string
    {
        return "STREAM.UPDATE.{$this->config->name}";
    }

    public function payload(): StreamConfig
    {
        return $this->config;
    }

    public function type(): string
    {
        return StreamInfo::class;
    }
}
