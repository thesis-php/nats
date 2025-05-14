<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<StreamDeleted>
 */
final readonly class DeleteStreamRequest implements Request
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(
        private string $name,
    ) {}

    public function endpoint(): string
    {
        return "STREAM.DELETE.{$this->name}";
    }

    public function payload(): null
    {
        return null;
    }

    public function type(): string
    {
        return StreamDeleted::class;
    }
}
