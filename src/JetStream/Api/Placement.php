<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class Placement implements \JsonSerializable
{
    /**
     * @param ?list<non-empty-string> $tags
     */
    public function __construct(
        public string $cluster,
        public ?array $tags = null,
        public ?string $preferred = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'cluster' => $this->cluster,
                'tags' => $this->tags,
                'preferred' => $this->preferred,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
