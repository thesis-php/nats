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
        public int $size = 0,
        public int $chunks = 0,
        public \DateTimeImmutable $mtime = new \DateTimeImmutable(),
        public ?string $digest = null,
        public ?bool $deleted = null,
        public ?string $description = null,
        public ?array $headers = null,
        public ?array $metadata = null,
        public ?ObjectMetaOptions $options = null,
    ) {}

    /**
     * @internal
     * @phpstan-assert-if-true !null $this->options
     */
    public function isLink(): bool
    {
        return $this->options?->link !== null;
    }

    /**
     * @internal
     */
    public function withoutTime(): self
    {
        return $this->withTime(new \DateTimeImmutable('0001-01-01 00:00:00', new \DateTimeZone('UTC')));
    }

    /**
     * @internal
     */
    public function withTime(\DateTimeImmutable $mtime): self
    {
        return new self(
            name: $this->name,
            bucket: $this->bucket,
            nuid: $this->nuid,
            size: $this->size,
            chunks: $this->chunks,
            mtime: $mtime,
            digest: $this->digest,
            deleted: $this->deleted,
            description: $this->description,
            headers: $this->headers,
            metadata: $this->metadata,
            options: $this->options,
        );
    }

    /**
     * @internal
     */
    public function asDeleted(): self
    {
        return new self(
            name: $this->name,
            bucket: $this->bucket,
            nuid: $this->nuid,
            size: 0,
            chunks: 0,
            mtime: $this->mtime,
            digest: '',
            deleted: true,
            description: $this->description,
            headers: $this->headers,
            metadata: $this->metadata,
            options: $this->options,
        );
    }

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
                    'link' => $this->options?->link,
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
