<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Thesis\Nats\JetStream\Api\StoreCompression;
use Thesis\Nats\JetStream\Api\StreamInfo;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class BucketStatus
{
    public const string BACKING_STORE = 'JetStream';

    /**
     * The number of stored messages: every kept revision of every key, delete and purge markers included.
     *
     * @var non-negative-int
     */
    public int $values;

    /** How many revisions of a key the bucket keeps. */
    public int $history;

    /** How long an entry is kept, null if forever. */
    public ?TimeSpan $ttl;

    /**
     * The size of the stored messages.
     *
     * @var non-negative-int
     */
    public int $bytes;

    /** @var non-empty-string */
    public string $backingStore;

    public bool $compressed;

    /** How long the markers of expired keys are kept, null if the server does not place them. */
    public ?TimeSpan $limitMarkerTtl;

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
        $this->values = $info->state->messages;
        $this->history = $info->config->maxMessagesPerSubject;
        $this->ttl = self::nonZero($info->config->maxAge);
        $this->bytes = $info->state->bytes;
        $this->backingStore = self::BACKING_STORE;
        $this->compressed = $info->config->compression !== StoreCompression::None;
        $this->limitMarkerTtl = self::nonZero($info->config->subjectDeleteMarkerTtl);
        $this->metadata = $info->config->metadata ?? [];
    }

    private static function nonZero(?TimeSpan $span): ?TimeSpan
    {
        return $span !== null && !$span->isZero() ? $span : null;
    }
}
