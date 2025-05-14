<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Exception\NoServerResponse;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\JetStream\Api;
use Thesis\Nats\JetStream\Api\Mapping;
use Thesis\Nats\JetStream\Api\Result\Result;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Json\NativeEncoder;
use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Serialization\ValinorSerializer;

/**
 * @api
 */
final class JetStream
{
    /** @var non-empty-string */
    private string $prefix = '$JS.API.';

    /**
     * @internal
     * @param ?non-empty-string $domain
     */
    public function __construct(
        private readonly Client $client,
        private readonly Serializer $serializer = new ValinorSerializer(),
        private readonly Encoder $encoder = new NativeEncoder(),
        ?string $domain = null,
    ) {
        if ($domain !== null) {
            $this->prefix = substr_replace($this->prefix, "\$JS.{$domain}.API.", 0);
        }
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
    public function createStream(Api\StreamConfig $config): Api\StreamInfo
    {
        return $this->request(new Api\CreateStreamRequest($config));
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
    public function createOrUpdateStream(Api\StreamConfig $config): Api\StreamInfo
    {
        try {
            return $this->updateStream($config);
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
    ): Api\ConsumerInfo {
        return $this->upsertConsumer($stream, $config, Api\CreateConsumerRequest::ACTION_CREATE);
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
    ): Api\ConsumerInfo {
        return $this->upsertConsumer($stream, $config);
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
        return $this->request(new Api\ConsumerDeleteRequest(
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
        return $this->request(new Api\ConsumerPauseRequest(
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
        return $this->request(new Api\ConsumerPauseRequest(
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
        $this->request(new Api\ConsumerUnpinRequest(
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
     * @template T
     * @param Api\Request<T> $request
     * @return T
     * @throws NatsException
     */
    private function request(Api\Request $request): mixed
    {
        $response = $this->client->request(
            subject: "{$this->prefix}{$request->endpoint()}",
            message: new Message(
                payload: $request->payload() !== null ? $this->encoder->encode($request->payload()) : null,
            ),
        );

        $payload = $response->message->payload ?? '{}';
        if ($payload === '') {
            throw new NoServerResponse();
        }

        /** @var Result<T> $result */
        $result = $this->serializer->deserialize(
            type: Result::type($request->type()),
            data: new Mapping\FixStructureSource($this->encoder->decode($payload)),
        );

        return $result->response();
    }
}
