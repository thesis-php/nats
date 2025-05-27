<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class AckResponse
{
    /**
     * @param non-empty-string $stream
     * @param non-negative-int $seq
     */
    public function __construct(
        public string $stream,
        public int $seq,
        public ?bool $duplicate = null,
        public ?string $domain = null,
    ) {}
}
