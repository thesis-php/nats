<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Time\TimeSpan;

/**
 * @api
 * @see https://github.com/nats-io/jsm.go/blob/main/schemas/jetstream/api/v1/stream_create_request.json
 */
final readonly class StreamConfig implements \JsonSerializable
{
    /**
     * @param non-empty-string $name
     * @param ?list<non-empty-string> $subjects
     * @param int<1, 5> $replicas
     * @param ?list<StreamSource> $sources
     * @param ?array<string, string> $metadata
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?array $subjects = null,
        public RetentionPolicy $retention = RetentionPolicy::Limits,
        public DiscardPolicy $discard = DiscardPolicy::Old,
        public int $maxConsumers = -1,
        public int $maxMessages = -1,
        public int $maxBytes = -1,
        public ?bool $discardNewPerSubject = null,
        public ?TimeSpan $maxAge = null,
        public int $maxMessagesPerSubject = -1,
        public ?int $maxMessageSize = null,
        public StorageType $storageType = StorageType::File,
        public int $replicas = 1,
        public ?bool $noAck = null,
        public ?TimeSpan $duplicateWindow = null,
        public ?Placement $placement = null,
        public ?StreamSource $mirror = null,
        public ?array $sources = null,
        public ?bool $sealed = null,
        public ?bool $denyDelete = null,
        public ?bool $denyPurge = null,
        public ?bool $allowRollup = null,
        public StoreCompression $compression = StoreCompression::None,
        public ?int $firstSeq = null,
        public ?SubjectTransformConfig $subjectTransform = null,
        public ?RePublish $rePublish = null,
        public bool $allowDirect = false,
        public bool $mirrorDirect = false,
        public ?StreamConsumerLimits $consumerLimits = null,
        public ?array $metadata = null,
        public ?bool $allowMessageTtl = null,
        public ?TimeSpan $subjectDeleteMarkerTtl = null,
    ) {}

    public function seal(): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            subjects: $this->subjects,
            retention: $this->retention,
            discard: $this->discard,
            maxConsumers: $this->maxConsumers,
            maxMessages: $this->maxMessages,
            maxBytes: $this->maxBytes,
            discardNewPerSubject: $this->discardNewPerSubject,
            maxAge: $this->maxAge,
            maxMessagesPerSubject: $this->maxMessagesPerSubject,
            maxMessageSize: $this->maxMessageSize,
            storageType: $this->storageType,
            replicas: $this->replicas,
            noAck: $this->noAck,
            duplicateWindow: $this->duplicateWindow,
            placement: $this->placement,
            mirror: $this->mirror,
            sources: $this->sources,
            sealed: true,
            denyDelete: $this->denyDelete,
            denyPurge: $this->denyPurge,
            allowRollup: $this->allowRollup,
            compression: $this->compression,
            firstSeq: $this->firstSeq,
            subjectTransform: $this->subjectTransform,
            rePublish: $this->rePublish,
            allowDirect: $this->allowDirect,
            mirrorDirect: $this->mirrorDirect,
            consumerLimits: $this->consumerLimits,
            metadata: $this->metadata,
            subjectDeleteMarkerTtl: $this->subjectDeleteMarkerTtl,
        );
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'name' => $this->name,
                'description' => $this->description,
                'subjects' => $this->subjects,
                'retention' => $this->retention,
                'max_consumers' => $this->maxConsumers,
                'max_msgs' => $this->maxMessages,
                'max_bytes' => $this->maxBytes,
                'discard' => $this->discard,
                'discard_new_per_subject' => $this->discardNewPerSubject,
                'max_age' => $this->maxAge?->toNanoseconds() ?? 0,
                'max_msgs_per_subject' => $this->maxMessagesPerSubject,
                'max_msg_size' => $this->maxMessageSize,
                'storage' => $this->storageType,
                'num_replicas' => $this->replicas,
                'no_ack' => $this->noAck,
                'duplicate_window' => $this->duplicateWindow?->toNanoseconds(),
                'placement' => $this->placement,
                'mirror' => $this->mirror,
                'sources' => $this->sources,
                'sealed' => $this->sealed,
                'deny_delete' => $this->denyDelete,
                'deny_purge' => $this->denyPurge,
                'allow_rollup_hdrs' => $this->allowRollup,
                'compression' => $this->compression,
                'first_seq' => $this->firstSeq,
                'subject_transform' => $this->subjectTransform,
                'republish' => $this->rePublish,
                'allow_direct' => $this->allowDirect,
                'mirror_direct' => $this->mirrorDirect,
                'consumer_limits' => $this->consumerLimits,
                'metadata' => $this->metadata ?: null,
                'allow_msg_ttl' => $this->allowMessageTtl,
                'subject_delete_marker_ttl' => $this->subjectDeleteMarkerTtl?->toNanoseconds(),
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
