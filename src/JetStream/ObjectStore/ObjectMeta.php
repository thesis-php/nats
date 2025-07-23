<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
final readonly class ObjectMeta
{
    /**
     * @param non-empty-string $name
     * @param array<string, list<string>> $headers
     * @param array<string, string> $metadata
     * @param ?positive-int $chunkSize
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public array $headers = [],
        public array $metadata = [],
        public ?int $chunkSize = null,
    ) {}
}
