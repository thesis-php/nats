<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Pipeline\Queue;
use Amp\TimeoutCancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Exception\ObjectIsInvalid;
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
use Thesis\Nats\JetStream\ObjectStore\Internal\DigestCalculator;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Amp\async;

/**
 * @api
 */
final readonly class Store
{
    /** @var int default chunk size is 128KB */
    private const int DEFAULT_CHUNK_SIZE = 128 * 1024;
    private const int GET_HEARTBEAT_SECONDS = 5;
    private const int GET_INACTIVE_THRESHOLD_SECONDS = 300;
    private const int CLEANUP_TIMEOUT_SECONDS = 5;

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
        if ($info === null || $info->deleted === true) {
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
            idleHeartbeat: TimeSpan::fromSeconds(self::GET_HEARTBEAT_SECONDS),
            replayPolicy: ReplayPolicy::Instant,
            inactiveThreshold: TimeSpan::fromSeconds(self::GET_INACTIVE_THRESHOLD_SECONDS),
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
     * @throws NatsException
     */
    public function put(ObjectMeta $meta, Reader|string $object): ObjectInfo
    {
        if (\is_string($object)) {
            $object = new StringReader($object);
        }

        $info = $this->info($meta->name);

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

            $this->js->publish(
                subject: "\$O.{$this->name}.M." . self::base64encode($meta->name),
                message: new Message(
                    payload: $this->json->encode($objectInfo),
                    headers: new Headers()
                        ->with(MsgRollup::header(), MsgRollup::ROLLUP_SUBJECT),
                ),
            );
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
        $info = $this->info($name);
        if ($info === null) {
            return;
        }

        $info = $info
            ->withoutTime()
            ->asDeleted();

        $this->js->publish(
            subject: "\$O.{$info->bucket}.M." . self::base64encode($info->name),
            message: new Message(
                payload: $this->json->encode($info),
                headers: new Headers()
                    ->with(MsgRollup::header(), MsgRollup::ROLLUP_SUBJECT),
            ),
        );

        $this->stream->purge(
            subject: "\$O.{$this->name}.C.{$info->nuid}",
        );
    }

    /**
     * @param non-empty-string $name
     */
    public function info(string $name): ?ObjectInfo
    {
        Validate::objectName($name);

        $msg = $this->stream->getLastMessageForSubject("\$O.{$this->name}.M." . self::base64encode($name));

        if ($msg !== null && $msg->payload !== null && $msg->payload !== '') {
            $info = $this->serializer->deserialize(
                ObjectInfo::class,
                $this->json->decode($msg->payload),
            );

            // The time the server stored the meta, like nats.go: the mtime in the payload is set by the writer.
            return $info->withTime($msg->headers?->get(Timestamp::Header) ?? $info->mtime);
        }

        return null;
    }

    /**
     * @param callable(ObjectInfo, Subscription): void $handler
     */
    public function watch(
        callable $handler,
        WatchConfig $config = new WatchConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        $serializer = $this->serializer;
        $json = $this->json;

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
                    $serializer,
                    $json,
                ): void {
                    $payload = $delivery->message->payload ?? '{}';
                    if ($payload === '') {
                        return;
                    }

                    $info = $serializer->deserialize(ObjectInfo::class, $json->decode($payload));

                    if ($config->ignoreDeletes && $info->deleted === true) {
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

        $target = $this->info($name);
        if ($target !== null && !$target->isLink()) {
            throw new ObjectIsInvalid('Object is already exists.');
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

        $this->js->publish(
            subject: "\$O.{$info->bucket}.M." . self::base64encode($info->name),
            message: new Message(
                payload: $this->json->encode($info->withoutTime()),
                headers: new Headers()
                    ->with(MsgRollup::header(), MsgRollup::ROLLUP_SUBJECT),
            ),
        );

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
