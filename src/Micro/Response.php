<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Thesis\Nats\Header;
use Thesis\Nats\Headers;

/**
 * @api
 */
final readonly class Response
{
    public function __construct(
        public ?string $data = null,
        public Headers $headers = new Headers(),
    ) {}

    public function withErrorCode(int $code): self
    {
        return new self(
            data: $this->data,
            headers: $this->headers->with(Header\ServiceErrorCode::header(), $code),
        );
    }

    /**
     * @param non-empty-string $description
     */
    public function withErrorDescription(string $description): self
    {
        return new self(
            data: $this->data,
            headers: $this->headers->with(Header\ServiceError::header(), $description),
        );
    }
}
