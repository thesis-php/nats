<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class RePublish implements \JsonSerializable
{
    /**
     * @param non-empty-string $dest
     * @param ?non-empty-string $src
     */
    public function __construct(
        public string $dest,
        public ?string $src = null,
        public ?bool $headersOnly = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'dest' => $this->dest,
                'src' => $this->src,
                'headers_only' => $this->headersOnly,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
