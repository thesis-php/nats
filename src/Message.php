<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Header\Incr;

/**
 * @api
 */
final readonly class Message
{
    public function __construct(
        public ?string $payload = null,
        public ?Headers $headers = null,
    ) {}

    public static function incr(int $value): self
    {
        return new self(
            headers: (new Headers())
                ->with(Incr::Header, $value),
        );
    }
}
