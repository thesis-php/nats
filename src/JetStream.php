<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Exception\FeatureIsNotSupported;
use Thesis\Nats\Exception\NoServerResponse;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\JetStream\Api;
use Thesis\Nats\JetStream\Api\Mapping;
use Thesis\Nats\JetStream\Api\Result\Result;
use Thesis\Nats\JetStream\KeyValue;
use Thesis\Nats\JetStream\ObjectStore;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Json\NativeEncoder;
use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Serialization\ValinorSerializer;

/**
 * @api
 */
final readonly class JetStream
{
    private Api\Router $router;

    /**
     * @internal
     * @param ?non-empty-string $domain
     */
    public function __construct(
        private Client $nats,
        private Serializer $serializer = new ValinorSerializer(),
        private Encoder $encoder = new NativeEncoder(),
        ?string $domain = null,
    ) {
        $this->router = new Api\Router($domain);
    }

    /**
     * @param non-empty-string $subject
     * @throws NatsException
     */
    public function publish(string $subject, Message $message): Api\AckResponse
    {
        return $this->mapResponse(
            message: $this->nats->request($subject, $message)->message,
            type: Api\AckResponse::class,
        );
    }

    /**
     * @throws NatsException
     */
    public function accountInfo(): Api\AccountInfo
    {
        return $this->request(new Api\AccountInfoRequest());
    }

    /**
     * @throws NatsException
     */
    public function createStream(Api\StreamConfig $config): JetStream\Stream
    {
        return $this->setupStream($this->request(new Api\CreateStreamRequest($config)));
    }

    /**
     * @throws NatsException
     */
    public function updateStream(Api\StreamConfig $config): Api\StreamInfo
    {
        return $this->request(new Api\UpdateStreamRequest($config));
    }

    /**
     * @throws NatsException
     */
    public function createOrUpdateStream(Api\StreamConfig $config): JetStream\Stream
    {
        try {
            return $this->setupStream($this->updateStream($config));
        } catch (StreamNotFound) {
            return $this->createStream($config);
        }
    }

    /**
     * @param non-empty-string $name
     * @throws NatsException
     */
    public function streamInfo(string $name): Api\StreamInfo
    {
        return $this->request(new Api\StreamInfoRequest($name));
    }

    /**
     * @param non-empty-string $name
     */
    public function stream(string $name): ?JetStream\Stream
    {
        try {
            $info = $this->streamInfo($name);
        } catch (StreamNotFound) {
            return null;
        }

        return new JetStream\Stream(
            info: $info,
            name: $name,
            js: $this,
        );
    }

    /**
     * @param non-empty-string $name
     * @throws NatsException
     */
    public function deleteStream(string $name): Api\StreamDeleted
    {
        return $this->request(new Api\DeleteStreamRequest($name));
    }

    /**
     * @param non-empty-string $name
     * @param ?non-negative-int $sequence
     * @param ?non-negative-int $keep
     * @throws NatsException
     */
    public function purgeStream(
        string $name,
        ?int $sequence = null,
        ?int $keep = null,
        ?string $subject = null,
    ): Api\StreamPurged {
        return $this->request(new Api\PurgeStreamRequest(
            name: $name,
            sequence: $sequence,
            keep: $keep,
            subject: $subject,
        ));
    }

    /**
     * @param ?non-empty-string $subject
     * @return iterable<non-empty-string>
     * @throws NatsException
     */
    public function streamNames(?string $subject = null): iterable
    {
        yield from $this->paginatedRequest(new Api\StreamNamesRequest($subject));
    }

    /**
     * @param ?non-empty-string $subject
     * @return iterable<Api\StreamInfo>
     * @throws NatsException
     */
    public function streamList(?string $subject = null): iterable
    {
        yield from $this->paginatedRequest(new Api\StreamListRequest($subject));
    }

    /**
     * @param non-empty-string $stream
     * @throws NatsException
     */
    public function createConsumer(
        string $stream,
        Api\ConsumerConfig $config = new Api\ConsumerConfig(),
    ): JetStream\Consumer {
        return $this->setupConsumer(
            $this->upsertConsumer($stream, $config, Api\CreateConsumerRequest::ACTION_CREATE),
        );
    }

    /**
     * @param non-empty-string $stream
     * @throws NatsException
     */
    public function updateConsumer(
        string $stream,
        Api\ConsumerConfig $config = new Api\ConsumerConfig(),
    ): Api\ConsumerInfo {
        return $this->upsertConsumer($stream, $config, Api\CreateConsumerRequest::ACTION_UPDATE);
    }

    /**
     * @param non-empty-string $stream
     * @throws NatsException
     */
    public function createOrUpdateConsumer(
        string $stream,
        Api\ConsumerConfig $config = new Api\ConsumerConfig(),
    ): JetStream\Consumer {
        return $this->setupConsumer(
            $this->upsertConsumer($stream, $config),
        );
    }

    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     * @throws NatsException
     */
    public function consumerInfo(
        string $stream,
        string $consumer,
    ): Api\ConsumerInfo {
        return $this->request(new Api\ConsumerInfoRequest(
            stream: $stream,
            consumer: $consumer,
        ));
    }

    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     * @throws NatsException
     */
    public function deleteConsumer(string $stream, string $consumer): Api\ConsumerDeleted
    {
        return $this->request(new Api\DeleteConsumerRequest(
            stream: $stream,
            consumer: $consumer,
        ));
    }

    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     * @throws NatsException
     */
    public function pauseConsumer(
        string $stream,
        string $consumer,
        \DateTimeImmutable $pauseUntil,
    ): Api\ConsumerPaused {
        return $this->request(new Api\PauseConsumerRequest(
            stream: $stream,
            consumer: $consumer,
            pauseUntil: $pauseUntil,
        ));
    }

    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     * @throws NatsException
     */
    public function resumeConsumer(
        string $stream,
        string $consumer,
    ): Api\ConsumerPaused {
        return $this->request(new Api\PauseConsumerRequest(
            stream: $stream,
            consumer: $consumer,
        ));
    }

    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     * @param non-empty-string $group
     * @throws NatsException
     */
    public function unpinConsumer(
        string $stream,
        string $consumer,
        string $group,
    ): void {
        $this->request(new Api\UnpinConsumerRequest(
            stream: $stream,
            consumer: $consumer,
            group: $group,
        ));
    }

    /**
     * @param non-empty-string $stream
     * @param ?non-empty-string $subject
     * @return iterable<non-empty-string>
     * @throws NatsException
     */
    public function consumerNames(
        string $stream,
        ?string $subject = null,
    ): iterable {
        yield from $this->paginatedRequest(new Api\ConsumerNamesRequest(
            stream: $stream,
            subject: $subject,
        ));
    }

    /**
     * @param non-empty-string $stream
     * @return iterable<Api\ConsumerInfo>
     * @throws NatsException
     */
    public function consumerList(string $stream): iterable
    {
        yield from $this->paginatedRequest(new Api\ConsumerListRequest($stream));
    }

    /**
     * @throws NatsException
     */
    public function createOrUpdateKeyValue(KeyValue\BucketConfig $config): KeyValue\Bucket
    {
        $allowMessageTtl = false;
        $subjectDeleteMarkerTtl = null;

        if ($config->limitMarkerTtl !== null) {
            $info = $this->accountInfo();

            if ($info->api->level < 1) {
                throw FeatureIsNotSupported::forLimitMarkerTtl();
            }

            $allowMessageTtl = true;
            $subjectDeleteMarkerTtl = $config->limitMarkerTtl;
        }

        $stream = $this->createOrUpdateStream(new Api\StreamConfig(
            name: "KV_{$config->bucket}",
            description: $config->description,
            subjects: ["\$KV.{$config->bucket}.>"],
            discard: Api\DiscardPolicy::New,
            maxConsumers: -1,
            maxMessages: -1,
            maxBytes: $config->maxBytes,
            maxAge: $config->ttl,
            maxMessagesPerSubject: $config->history,
            maxMessageSize: $config->maxValueSize,
            storageType: $config->storageType,
            replicas: $config->replicas,
            duplicateWindow: $config->ttl,
            placement: $config->placement,
            denyDelete: true,
            allowRollup: true,
            compression: $config->compression ? Api\StoreCompression::S2 : Api\StoreCompression::None,
            rePublish: $config->rePublish,
            allowDirect: true,
            allowMessageTtl: $allowMessageTtl,
            subjectDeleteMarkerTtl: $subjectDeleteMarkerTtl,
        ));

        return new KeyValue\Bucket(
            name: $config->bucket,
            nats: $this->nats,
            js: $this,
            stream: $stream,
            prefix: "\$KV.{$config->bucket}.",
            jsPrefix: $this->router->prefix() !== Api\Router::DEFAULT_PREFIX ? $this->router->prefix() : null,
        );
    }

    /**
     * @param non-empty-string $bucket
     * @throws NatsException
     */
    public function deleteKeyValue(string $bucket): void
    {
        $this->deleteStream("KV_{$bucket}");
    }

    /**
     * @param non-empty-string $bucket
     */
    public function keyValue(string $bucket): ?KeyValue\Bucket
    {
        $stream = $this->stream("KV_{$bucket}");

        if ($stream !== null) {
            return new KeyValue\Bucket(
                name: $bucket,
                nats: $this->nats,
                js: $this,
                stream: $stream,
                prefix: "\$KV.{$bucket}.",
                jsPrefix: $this->router->prefix() !== Api\Router::DEFAULT_PREFIX ? $this->router->prefix() : null,
            );
        }

        return null;
    }

    /**
     * @return iterable<non-empty-string>
     * @throws NatsException
     */
    public function keyValueNames(): iterable
    {
        foreach ($this->paginatedRequest(new Api\StreamNamesRequest('$KV.*.>')) as $bucket) {
            if (($name = ltrim($bucket, 'KV_')) !== '') {
                yield $name;
            }
        }
    }

    /**
     * @return iterable<KeyValue\BucketInfo>
     * @throws NatsException
     */
    public function keyValueList(): iterable
    {
        /** @var Api\StreamInfo $info */
        foreach ($this->paginatedRequest(new Api\StreamListRequest('$KV.*.>')) as $info) {
            if (str_starts_with($info->config->name, 'KV_')) {
                /** @var non-empty-string $name */
                $name = ltrim($info->config->name, 'KV_');

                yield new KeyValue\BucketInfo(
                    name: $name,
                    info: $info,
                );
            }
        }
    }

    /**
     * @throws NatsException
     */
    public function createOrUpdateObjectStore(ObjectStore\StoreConfig $config): ObjectStore\Store
    {
        $stream = $this->createOrUpdateStream(new Api\StreamConfig(
            name: "OBJ_{$config->store}",
            description: $config->description,
            subjects: [
                "\$O.{$config->store}.C.>",
                "\$O.{$config->store}.M.>",
            ],
            discard: Api\DiscardPolicy::New,
            maxBytes: $config->maxBytes ?? -1,
            maxAge: $config->ttl,
            storageType: $config->storageType,
            replicas: max($config->replicas, 1),
            duplicateWindow: $config->ttl,
            placement: $config->placement,
            allowRollup: true,
            compression: $config->compression ? Api\StoreCompression::S2 : Api\StoreCompression::None,
            allowDirect: true,
            metadata: $config->metadata,
        ));

        return new ObjectStore\Store(
            name: $config->store,
            nats: $this->nats,
            js: $this,
            stream: $stream,
        );
    }

    /**
     * @param non-empty-string $bucket
     * @throws NatsException
     */
    public function deleteObjectStore(string $bucket): void
    {
        $this->deleteStream("OBJ_{$bucket}");
    }

    /**
     * @param non-empty-string $store
     */
    public function objectStore(string $store): ?ObjectStore\Store
    {
        $stream = $this->stream("OBJ_{$store}");

        if ($stream !== null) {
            return new ObjectStore\Store(
                name: $store,
                nats: $this->nats,
                js: $this,
                stream: $stream,
            );
        }

        return null;
    }

    /**
     * @return iterable<non-empty-string>
     * @throws NatsException
     */
    public function objectStoreNames(): iterable
    {
        foreach ($this->paginatedRequest(new Api\StreamNamesRequest('$O.*.C.>')) as $store) {
            if (($name = ltrim($store, 'OBJ_')) !== '') {
                yield $name;
            }
        }
    }

    /**
     * @return iterable<ObjectStore\ObjectStoreInfo>
     * @throws NatsException
     */
    public function objectStoreList(): iterable
    {
        /** @var Api\StreamInfo $info */
        foreach ($this->paginatedRequest(new Api\StreamListRequest('$O.*.C.>')) as $info) {
            if (str_starts_with($info->config->name, 'OBJ_')) {
                /** @var non-empty-string $name */
                $name = ltrim($info->config->name, 'OBJ_');

                yield new ObjectStore\ObjectStoreInfo(
                    name: $name,
                    info: $info,
                );
            }
        }
    }

    /**
     * @internal
     * @param non-empty-string $endpoint
     */
    public function rawRequest(string $endpoint, mixed $payload = null): Message
    {
        return $this->nats
            ->request(
                subject: $this->router->route($endpoint),
                message: new Message(
                    payload: $payload !== null ? $this->encoder->encode($payload) : null,
                ),
            )
            ->message;
    }

    /**
     * @internal
     * @template T of object
     * @param Api\Request<T> $request
     * @return T
     * @throws NatsException
     */
    public function request(Api\Request $request): mixed
    {
        return $this->mapResponse(
            message: $this->rawRequest($request->endpoint(), $request->payload()),
            type: $request->type(),
        );
    }

    /**
     * @param non-empty-string $stream
     * @param ?Api\CreateConsumerRequest::ACTION_* $action
     * @throws NatsException
     */
    private function upsertConsumer(string $stream, Api\ConsumerConfig $config, ?string $action = null): Api\ConsumerInfo
    {
        $consumerName = $config->name ?? $config->durableName;

        if ($consumerName === null || $consumerName === '') {
            $consumerName = Id\generateUniqueId(10);
        }

        return $this->request(new Api\CreateConsumerRequest(
            stream: $stream,
            consumer: $consumerName,
            config: $config,
            action: $action,
        ));
    }

    private function setupConsumer(Api\ConsumerInfo $info): JetStream\Consumer
    {
        return new JetStream\Consumer(
            info: $info,
            name: $info->name,
            stream: $info->streamName,
            js: $this,
            nats: $this->nats,
            router: $this->router,
            json: $this->encoder,
        );
    }

    private function setupStream(Api\StreamInfo $info): JetStream\Stream
    {
        return new JetStream\Stream(
            info: $info,
            name: $info->config->name,
            js: $this,
        );
    }

    /**
     * @template T
     * @param Api\PaginatedRequest<Api\PaginatedResponse<T>> $request
     * @return iterable<T>
     * @throws NatsException
     */
    private function paginatedRequest(Api\PaginatedRequest $request): iterable
    {
        $offset = 0;

        while (true) {
            $response = $this->request($request->withOffset($offset));

            yield from $response;

            $offset += \count($response);

            if ($offset >= $response->total()) {
                break;
            }
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private function mapResponse(Message $message, string $type): object
    {
        $payload = $message->payload ?? '{}';
        if ($payload === '') {
            throw new NoServerResponse();
        }

        /** @var Result<T> $result */
        $result = $this->serializer->deserialize(
            type: Result::type($type),
            data: new Mapping\FixStructureSource($this->encoder->decode($payload)),
        );

        return $result->response();
    }
}
