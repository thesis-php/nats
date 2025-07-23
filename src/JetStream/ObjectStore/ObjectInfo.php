<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
final readonly class ObjectInfo implements \JsonSerializable
{
    /**
     * @param ?array<string, list<string>> $headers
     * @param ?array<string, string> $metadata
     */
    public function __construct(
        public string $name,
        public string $bucket,
        public string $nuid,
        public int $size,
        public int $chunks,
        public \DateTimeImmutable $mtime,
        public ?string $digest = null,
        public ?bool $deleted = null,
        public ?string $description = null,
        public ?array $headers = null,
        public ?array $metadata = null,
        public ?ObjectMetaOptions $options = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'name' => $this->name,
                'bucket' => $this->bucket,
                'description' => $this->description,
                'headers' => $this->headers ?: null,
                'metadata' => $this->metadata ?: null,
                'options' => [
                    'max_chunk_size' => $this->options?->maxChunkSize,
                ],
                'nuid' => $this->nuid,
                'size' => $this->size,
                'chunks' => $this->chunks,
                'digest' => $this->digest,
                'deleted' => $this->deleted,
                'mtime' => $this->mtime->format(\DateTimeInterface::RFC3339),
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
