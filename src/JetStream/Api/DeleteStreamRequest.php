<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Nats\Internal\Validation\Validate;

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
    ) {
        Validate::name($this->name);
    }

    public function endpoint(): string
    {
        return ApiMethod::DeleteStream->compile($this->name);
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
