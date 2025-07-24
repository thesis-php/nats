<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\Pipeline;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery;
use Thesis\Nats\Exception\ObjectIsInvalid;
use Thesis\Nats\Header\MsgRollup;
use Thesis\Nats\Header\Timestamp;
use Thesis\Nats\Headers;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\ObjectStore\Internal\DigestCalculator;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\Serialization\Serializer;

/**
 * @api
 */
final readonly class Store
{
    /** @var int default chunk size is 128KB */
    private const int DEFAULT_CHUNK_SIZE = 128 * 1024;

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
        if ($info === null || $info->deleted) {
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

        /** @var Pipeline\Queue<non-empty-string> $queue */
        $queue = new Pipeline\Queue();
        $object = new StoredObject($info, $queue->iterate());

        if ($info->size === 0) {
            $queue->complete();

            return $object;
        }

        $this->stream->createOrUpdateConsumer(new JetStream\Api\ConsumerConfig(
            deliverSubject: $id = Id\generateInboxId(),
            filterSubject: "\$O.{$this->name}.C.{$info->nuid}",
        ));

        $this->nats->subscribe($id, static function (
            Delivery $delivery,
            Client $nats,
            string $sid,
        ) use ($queue): void {
            $reply = $delivery->replyTo;
            if ($reply === null) {
                $queue->error(new ObjectIsInvalid('No reply in Delivery.'));
                $nats->unsubscribe($sid);

                return;
            }

            $metadata = JetStream\Metadata::parse($reply);

            $payload = $delivery->message->payload;

            if ($payload !== null && $payload !== '') {
                $queue->push($payload);
            }

            if ($metadata->pending === 0) {
                $queue->complete();
                $nats->unsubscribe($sid);
            }
        });

        return $object;
    }

    /**
     * @param Reader|non-empty-string $object
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

        if ($size === 0) {
            throw new \LogicException('Empty object provided.');
        }

        $encodedDigest = self::base64encode($digest->finish());
        $subject = "\$O.{$this->name}.M." . self::base64encode($meta->name);

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
            subject: $subject,
            message: new Message(
                payload: $this->json->encode($objectInfo),
                headers: (new Headers())
                    ->with(MsgRollup::header(), MsgRollup::ROLLUP_SUBJECT),
            ),
        );

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
                headers: (new Headers())
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
        $msg = $this->stream->getLastMessageForSubject("\$O.{$this->name}.M." . self::base64encode($name));

        if ($msg !== null && $msg->payload !== null && $msg->payload !== '') {
            $data = $this->json->decode($msg->payload);
            $data['mtime'] = ($msg->headers?->get(Timestamp::Header) ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

            return $this->serializer->deserialize(
                ObjectInfo::class,
                $data,
            );
        }

        return null;
    }

    /**
     * @param non-empty-string $name
     * @throws NatsException
     */
    public function addLink(string $name, ObjectInfo $object): ObjectInfo
    {
        if ($object->deleted) {
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
                headers: (new Headers())
                    ->with(MsgRollup::header(), MsgRollup::ROLLUP_SUBJECT),
            ),
        );

        return $info;
    }

    private function base64encode(string $name): string
    {
        return rtrim(strtr(base64_encode($name), '+/', '-_'), '=');
    }
}
