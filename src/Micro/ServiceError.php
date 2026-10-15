<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * An error response of a service endpoint: sent with {@see Request::error()}, or thrown from an endpoint handler
 * to reply with the given code and description.
 *
 * @api
 */
final class ServiceError extends \RuntimeException
{
    /**
     * @param non-empty-string $description
     */
    public function __construct(
        int $code,
        public readonly string $description,
        public readonly ?string $data = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($description, $code, $previous);
    }
}
