<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
final readonly class ObjectLink implements \JsonSerializable
{
    public function __construct(
        public string $bucket,
        public ?string $name = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'bucket' => $this->bucket,
                'name' => $this->name,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
