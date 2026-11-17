<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Thesis\Nats\JetStream\Api\StorageType;
use Thesis\Nats\JetStream\Api\StoreCompression;
use Thesis\Nats\JetStream\Api\StreamInfo;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class ObjectStoreStatus
{
    public const string BACKING_STORE = 'JetStream';

    public ?string $description;

    /** How long an object is kept, null if forever. */
    public ?TimeSpan $ttl;

    public StorageType $storage;

    public int $replicas;

    /** A sealed store cannot be changed, see {@see Store::seal()}. */
    public bool $sealed;

    /**
     * The size of the stored chunks and metas.
     *
     * @var non-negative-int
     */
    public int $size;

    /** @var non-empty-string */
    public string $backingStore;

    public bool $compressed;

    /** @var array<string, string> */
    public array $metadata;

    /**
     * @internal
     * @param non-empty-string $bucket
     */
    public function __construct(
        public string $bucket,
        public StreamInfo $info,
    ) {
        $this->description = $info->config->description;
        $this->ttl = $info->config->maxAge !== null && !$info->config->maxAge->isZero() ? $info->config->maxAge : null;
        $this->storage = $info->config->storageType;
        $this->replicas = $info->config->replicas;
        $this->sealed = $info->config->sealed === true;
        $this->size = $info->state->bytes;
        $this->backingStore = self::BACKING_STORE;
        $this->compressed = $info->config->compression !== StoreCompression::None;
        $this->metadata = $info->config->metadata ?? [];
    }
}
