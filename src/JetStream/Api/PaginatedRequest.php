<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-covariant ResponseType
 * @template-extends Request<ResponseType>
 */
interface PaginatedRequest extends Request
{
    /**
     * @param non-negative-int $offset
     * @return self<ResponseType>
     */
    public function withOffset(int $offset): self;
}
