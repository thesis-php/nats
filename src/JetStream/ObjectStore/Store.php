<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Pipeline\Queue;
use Amp\TimeoutCancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Exception\FileIsNotAccessible;
use Thesis\Nats\Exception\ObjectAlreadyExists;
use Thesis\Nats\Exception\ObjectIsInvalid;
use Thesis\Nats\Exception\ObjectListIsIncomplete;
use Thesis\Nats\Exception\ObjectNotFound;
use Thesis\Nats\Header\MsgRollup;
use Thesis\Nats\Header\Timestamp;
use Thesis\Nats\Headers;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\Validation\Validate;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Api\ReplayPolicy;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\InitialValues;
use Thesis\Nats\JetStream\ObjectStore\Internal\DigestCalculator;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\ByteStream\pipe;

/**
 * @api
 */
final readonly class Store
{
    /** @var int default chunk size is 128KB */
    private const int DEFAULT_CHUNK_SIZE = 128 * 1024;
    private const int HEARTBEAT_SECONDS = 5;
    private const int INACTIVE_THRESHOLD_SECONDS = 300;
    private const int CLEANUP_TIMEOUT_SECONDS = 5;

    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public string $name,
        private Client $nats,
        private JetStream $js,
        private JetStream\Stream $stream,
        private Encoder $json,
        private Serializer $serializer,
    ) {}

    /**
     * @param non-empty-string $name
     * @throws NatsException
     */
    public function get(string $name): ?StoredObject
    {
        $info = $this->info($name);
        if ($info === null) {
            return null;
        }

        if ($info->isLink()) {
            $bucket = $info->options->link->bucket ?? '';

            if ($bucket === '') {
                throw new ObjectIsInvalid('Link bucket name is empty.');
            }

            $name = $info->options->link->name ?? '';

            if ($name === '') {
                throw new ObjectIsInvalid('Object name is empty.');
            }

            if ($bucket === $this->name) {
                return $this->get($name);
            }

            return $this->js
                ->objectStore($bucket)
                ?->get($name);
        }

        if ($info->size === 0) {
            return new StoredObject($info);
        }

        /** @var Queue<string> $chunks */
        $chunks = new Queue(max($info->chunks, 1));
        $collector = new Internal\ChunkCollector($info, $chunks);

        // An ordered consumer of nats.go: no acks, flow control with heartbeats, a replica in memory.
        $consumer = $this->stream->createOrUpdateConsumer(new JetStream\Api\ConsumerConfig(
            description: 'object store get consumer',
            deliverPolicy: DeliverPolicy::All,
            deliverSubject: $this->nats->newInbox(),
            ackPolicy: AckPolicy::None,
            maxDeliver: 1,
            filterSubject: "\$O.{$this->name}.C.{$info->nuid}",
            flowControl: true,
            idleHeartbeat: TimeSpan::fromSeconds(self::HEARTBEAT_SECONDS),
            replayPolicy: ReplayPolicy::Instant,
            inactiveThreshold: TimeSpan::fromSeconds(self::INACTIVE_THRESHOLD_SECONDS),
            replicas: 1,
            memoryStorage: true,
        ));

        // Each chunk is a message, so fewer messages than chunks means some of them are gone.
        if ($consumer->info->numPending < $info->chunks) {
            $completion = async($this->deleteConsumer(...), $consumer);
            $completion->ignore();

            throw new ObjectIsInvalid("Object '{$info->name}' is missing chunks: expected {$info->chunks}, stored {$consumer->info->numPending}.");
        }

        $subscription = $consumer->push($collector);

        // The reader gets the end of the object or the error first, the consumer is deleted afterwards.
        $completion = async(function () use (
            $subscription,
            $consumer,
            $collector,
            $chunks,
        ): void {
            try {
                try {
                    $subscription->awaitCompletion($collector->exhausted());
                } catch (CancelledException) {
                    $subscription->stop();
                }

                $collector->verify();
                $chunks->complete();
            } catch (\Throwable $e) {
                $chunks->error($e);
            }

            $this->deleteConsumer($consumer);
        });
        $completion->ignore();

        return new StoredObject(
            $info,
            new ReadableIterableStream($chunks->iterate()),
        );
    }

    /**
     * Chunks already published are purged if reading the object or publishing fails.
     *
     * @throws StreamException if a {@see ReadableStream} fails
     * @throws NatsException
     */
    public function put(ObjectMeta $meta, Reader|ReadableStream|string $object): ObjectInfo
    {
        $object = match (true) {
            \is_string($object) => new StringReader($object),
            $object instanceof ReadableStream => new StreamReader($object),
            default => $object,
        };

        $info = $this->info($meta->name, showDeleted: true);

        $nuid = Id\generateUniqueId();
        $chunkSubject = "\$O.{$this->name}.C.{$nuid}";

        $chunks = 0;
        $size = 0;
        $chunkSize = $meta->chunkSize ?? self::DEFAULT_CHUNK_SIZE;
        $digest = DigestCalculator::sha256();

        try {
            while (!$object->eof()) {
                $data = $object->read($chunkSize);

                if ($data === null) {
                    break;
                }

                $digest->update($data);
                $size += \strlen($data);
                ++$chunks;

                $this->js->publish($chunkSubject, new Message($data));
            }

            $encodedDigest = self::base64encode($digest->finish());

            $objectInfo = new ObjectInfo(
                name: $meta->name,
                bucket: $this->name,
                nuid: $nuid,
                size: $size,
                chunks: $chunks,
                mtime: new \DateTimeImmutable(),
                digest: "{$digest->name()}={$encodedDigest}",
                deleted: false,
                description: $meta->description,
                headers: $meta->headers,
                metadata: $meta->metadata,
                options: new ObjectMetaOptions(
                    maxChunkSize: $meta->chunkSize,
                ),
            );

            $this->publishMeta($objectInfo);
        } catch (\Throwable $e) {
            if ($chunks > 0) {
                $this->purgeChunks($chunkSubject);
            }

            throw $e;
        }

        if ($info !== null) {
            $this->stream->purge(
                subject: "\$O.{$this->name}.C.{$info->nuid}",
            );
        }

        return $objectInfo;
    }

    /**
     * @param non-empty-string $name
     */
    public function delete(string $name): void
    {
        $info = $this->info($name, showDeleted: true);
        if ($info === null) {
            return;
        }

        $this->publishMeta($info->asDeleted());

        $this->stream->purge(
            subject: "\$O.{$this->name}.C.{$info->nuid}",
        );
    }

    /**
     * Changes the name, the description, the headers and the metadata of the object, the data stays as it is.
     * {@see ObjectMeta::$chunkSize} is ignored: the chunks are already stored.
     *
     * @param non-empty-string $name
     * @throws ObjectNotFound if there is no object with this name or it is deleted
     * @throws ObjectAlreadyExists if the object is renamed and the new name is taken by another object
     * @throws NatsException
     */
    public function updateMeta(string $name, ObjectMeta $meta): ObjectInfo
    {
        $info = $this->info($name) ?? throw new ObjectNotFound("Object '{$name}' is not found.");

        if ($meta->name !== $name && $this->info($meta->name) !== null) {
            throw new ObjectAlreadyExists("Object '{$meta->name}' already exists.");
        }

        $info = $info->withMeta($meta);
        $this->publishMeta($info);

        // The meta is stored under the new name, so the one under the old name is removed.
        if ($meta->name !== $name) {
            $this->stream->purge(
                subject: "\$O.{$this->name}.M." . self::base64encode($name),
            );
        }

        return $info->withTime(new \DateTimeImmutable());
    }

    /**
     * @param non-empty-string $name
     * @param bool $showDeleted return the meta of a deleted object instead of null
     */
    public function info(string $name, bool $showDeleted = false): ?ObjectInfo
    {
        Validate::objectName($name);

        $msg = $this->stream->getLastMessageForSubject("\$O.{$this->name}.M." . self::base64encode($name));

        if ($msg === null || $msg->payload === null || $msg->payload === '') {
            return null;
        }

        $info = $this->decodeInfo($msg->payload);

        if (!$showDeleted && $info->deleted === true) {
            return null;
        }

        // The time the server stored the meta, like nats.go: the mtime in the payload is set by the writer.
        return $info->withTime($msg->headers?->get(Timestamp::Header) ?? $info->mtime);
    }

    /**
     * Returns the metas of the objects in the store, links included, in the order they were last changed.
     *
     * @param bool $showDeleted include the metas of deleted objects
     * @return list<ObjectInfo>
     * @throws ObjectListIsIncomplete if the list stopped before every meta was received, a part is never returned
     * @throws NatsException
     */
    public function list(bool $showDeleted = false, ?Cancellation $cancellation = null): array
    {
        // An ordered consumer of nats.go: no acks, flow control with heartbeats, a replica in memory.
        $consumer = $this->stream->createOrUpdateConsumer(
            new JetStream\Api\ConsumerConfig(
                description: 'object store list consumer',
                deliverPolicy: DeliverPolicy::LastPerSubject,
                deliverSubject: $this->nats->newInbox(),
                ackPolicy: AckPolicy::None,
                maxDeliver: 1,
                filterSubject: "\$O.{$this->name}.M.>",
                flowControl: true,
                idleHeartbeat: TimeSpan::fromSeconds(self::HEARTBEAT_SECONDS),
                replayPolicy: ReplayPolicy::Instant,
                inactiveThreshold: TimeSpan::fromSeconds(self::INACTIVE_THRESHOLD_SECONDS),
                replicas: 1,
                memoryStorage: true,
            ),
            $cancellation,
        );

        $initialValues = new InitialValues($consumer->info->numPending);
        $collector = new Internal\InfoCollector($this->decodeDelivered(...), $showDeleted, $initialValues);

        try {
            if (!$initialValues->delivered()) {
                $unwatch = $this->nats->watchConnection($collector->disconnected(...), static function (): void {});
                $subscription = null;

                try {
                    $subscription = $consumer->push($collector, cancellation: $cancellation);

                    $completion = async($subscription->awaitCompletion(...));
                    $completion->ignore();

                    Future\awaitFirst([$initialValues->future(), $completion, $collector->lost()], $cancellation);
                } finally {
                    $unwatch();
                    $subscription?->stop();
                }

                // The subscription completed first, e.g. the client was stopped.
                if (!$initialValues->delivered()) {
                    throw new ObjectListIsIncomplete("The list of object store '{$this->name}' stopped before every object was received.");
                }
            }
        } finally {
            $this->deleteConsumer($consumer);
        }

        return $collector->objects;
    }

    /**
     * @throws NatsException
     */
    public function status(): ObjectStoreStatus
    {
        return new ObjectStoreStatus($this->name, $this->stream->actualInfo());
    }

    /**
     * Puts the file into the store, under its path like nats.go unless the meta tells another name.
     *
     * @param non-empty-string $path
     * @throws FileIsNotAccessible
     * @throws NatsException
     */
    public function putFile(string $path, ?ObjectMeta $meta = null): ObjectInfo
    {
        $meta ??= new ObjectMeta($path);

        // A directory opens on Linux, and reading it fails.
        if (!is_file($path)) {
            throw new FileIsNotAccessible("The file '{$path}' cannot be opened: not a regular file.");
        }

        $handle = self::open($path, 'rb');

        try {
            return $this->put($meta, new ResourceReader($handle));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Writes the object into the file, replacing it. The object is first written to a temporary file next to it, which is then
     * renamed over the file, so an existing file is left untouched if there is no such object or reading the object fails.
     *
     * @param non-empty-string $name
     * @param non-empty-string $path
     * @throws FileIsNotAccessible
     * @throws NatsException
     */
    public function getFile(string $name, string $path, ?Cancellation $cancellation = null): ?ObjectInfo
    {
        $object = $this->get($name);
        if ($object === null) {
            return null;
        }

        $temporary = \sprintf('%s.%s.part', $path, bin2hex(random_bytes(6)));
        $file = new WritableResourceStream(self::open($temporary, 'xb', $path));

        try {
            pipe($object->stream, $file, $cancellation);
            $file->end();

            error_clear_last();
            if (!@rename($temporary, $path)) {
                throw new FileIsNotAccessible(\sprintf(
                    "The file '%s' cannot be replaced: %s.",
                    $path,
                    error_get_last()['message'] ?? 'unknown error',
                ));
            }
        } catch (\Throwable $e) {
            $file->close();
            @unlink($temporary);

            throw $e;
        }

        return $object->info;
    }

    /**
     * @param callable(ObjectInfo, Subscription): void $handler
     */
    public function watch(
        callable $handler,
        WatchConfig $config = new WatchConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        $decode = $this->decodeDelivered(...);

        return $this->stream
            ->createOrUpdateConsumer(new JetStream\Api\ConsumerConfig(
                description: 'object store consumer',
                deliverPolicy: $config->withHistory ? DeliverPolicy::LastPerSubject : DeliverPolicy::New,
                deliverSubject: $this->nats->newInbox(),
                filterSubject: "\$O.{$this->name}.M.>",
            ))
            ->push(
                static function (JetStreamDelivery $delivery, Subscription $subscription) use (
                    $config,
                    $handler,
                    $decode,
                ): void {
                    $info = $decode($delivery);

                    if ($info === null || ($config->ignoreDeletes && $info->deleted === true)) {
                        return;
                    }

                    $handler($info, $subscription);
                },
                cancellation: $cancellation,
            );
    }

    /**
     * @param non-empty-string $name
     * @throws NatsException
     */
    public function addLink(string $name, ObjectInfo $object): ObjectInfo
    {
        if ($object->deleted === true) {
            throw new ObjectIsInvalid('Not allowed to link to a deleted object.');
        }

        if ($object->isLink()) {
            throw new ObjectIsInvalid('Not allowed to link to another link.');
        }

        $target = $this->info($name, showDeleted: true);
        if ($target !== null && !$target->isLink()) {
            throw new ObjectAlreadyExists("Object '{$name}' already exists.");
        }

        $info = new ObjectInfo(
            name: $name,
            bucket: $this->name,
            nuid: Id\generateUniqueId(),
            options: new ObjectMetaOptions(
                link: new ObjectLink(
                    bucket: $object->bucket,
                    name: $object->name,
                ),
            ),
        );

        $this->publishMeta($info);

        return $info;
    }

    /**
     * @throws NatsException
     */
    public function seal(): void
    {
        $info = $this->stream->actualInfo();
        $this->js->updateStream($info->config->seal());
    }

    /**
     * The meta replaces the previous one of the object. The time is not stored, like in nats.go:
     * the readers take the time the server stored the meta.
     *
     * @throws NatsException
     */
    private function publishMeta(ObjectInfo $info): void
    {
        $this->js->publish(
            subject: "\$O.{$info->bucket}.M." . self::base64encode($info->name),
            message: new Message(
                payload: $this->json->encode($info->withoutTime()),
                headers: new Headers()
                    ->with(MsgRollup::header(), MsgRollup::ROLLUP_SUBJECT),
            ),
        );
    }

    /**
     * Decodes a meta delivered by a consumer, with the time the server stored it.
     */
    private function decodeDelivered(JetStreamDelivery $delivery): ?ObjectInfo
    {
        $payload = $delivery->message->payload;
        if ($payload === null || $payload === '') {
            return null;
        }

        $info = $this->decodeInfo($payload);

        return $delivery->metadata !== null ? $info->withTime($delivery->metadata->timestamp) : $info;
    }

    /**
     * @param non-empty-string $payload
     */
    private function decodeInfo(string $payload): ObjectInfo
    {
        return $this->serializer->deserialize(ObjectInfo::class, $this->json->decode($payload));
    }

    /**
     * @param non-empty-string $path
     * @return resource
     * @throws FileIsNotAccessible
     */
    private static function open(string $path, string $mode, ?string $reportedPath = null): mixed
    {
        // The last error is cleared first, so that an earlier unrelated one is not reported as the reason.
        error_clear_last();
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new FileIsNotAccessible(\sprintf(
                "The file '%s' cannot be opened: %s.",
                $reportedPath ?? $path,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        return $handle;
    }

    /**
     * A cleanup: bounded in time and not reported, the inactive threshold removes the consumer anyway.
     */
    private function deleteConsumer(JetStream\Consumer $consumer): void
    {
        try {
            $consumer->delete(new TimeoutCancellation(self::CLEANUP_TIMEOUT_SECONDS));
        } catch (\Throwable) {
        }
    }

    /**
     * A cleanup: bounded in time and not reported, the error that made the put fail matters more.
     */
    private function purgeChunks(string $subject): void
    {
        try {
            $this->stream->purge(
                subject: $subject,
                cancellation: new TimeoutCancellation(self::CLEANUP_TIMEOUT_SECONDS),
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Padding must be kept: nats.go encodes object names in meta subjects and object
     * digests with padded url-safe base64 (base64.URLEncoding) and rejects unpadded
     * digests on read.
     */
    private function base64encode(string $name): string
    {
        return strtr(base64_encode($name), '+/', '-_');
    }
}
