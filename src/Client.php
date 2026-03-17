<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Cancellation;
use Amp\Future;
use Amp\Pipeline;
use Thesis\Nats\Internal\Connection;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\Iter;
use Thesis\Nats\Internal\Rpc;
use Thesis\Nats\Internal\Subscription\SubscriptionHandler;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\Acks;
use Thesis\Nats\JetStream\Metadata;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Json\NativeEncoder;
use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Serialization\ValinorSerializer;
use function Amp\async;

/**
 * @api
 * @phpstan-type MessageHandler = callable(Delivery, Subscription): void
 * @phpstan-type Subscribe = \Closure(non-empty-string, MessageHandler, ?non-empty-string=, positive-int=): Subscription
 */
final class Client
{
    private const int DEFAULT_DELIVERY_BUFFER_SIZE = 1_000;

    private readonly Connection\ConnectionFactory $connectionFactory;

    /** @var ?Future<Connection\Connection> */
    private ?Future $connection = null;

    /** @var ?Future<Rpc\Handler> */
    private ?Future $rpc = null;

    /** @var array<non-empty-string, array{callable(Delivery): bool, ?Subscription}> */
    private array $subscribers = [];

    private readonly Id\SubscriptionIdGenerator $subscriptionIdGenerator;

    private readonly Acks $acks;

    public function __construct(
        private readonly Config $config,
        private readonly Serializer $serializer = new ValinorSerializer(),
        private readonly Encoder $encoder = new NativeEncoder(),
    ) {
        $this->connectionFactory = Connection\SocketConnectionFactory::fromConfig($this->config);
        $this->subscriptionIdGenerator = new Id\SubscriptionIdGenerator();
        $this->acks = Acks::fromClient($this);
    }

    /**
     * @param non-empty-string $uri
     * @throws \InvalidArgumentException
     */
    public static function fromURI(string $uri): self
    {
        return new self(Config::fromURI($uri));
    }

    public static function default(): self
    {
        return new self(Config::default());
    }

    /**
     * @param ?non-empty-string $domain
     * @throws NatsException
     */
    public function jetStream(?string $domain = null, ?Cancellation $cancellation = null): JetStream
    {
        $info = $this->connection($cancellation)->info();

        if (!$info->supportJetstream) {
            throw Exception\FeatureIsNotSupported::forJetStream($info->serverVersion);
        }

        return new JetStream(
            nats: $this,
            serializer: $this->serializer,
            encoder: $this->encoder,
            domain: $domain ?? $this->config->jetStreamDomain,
        );
    }

    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $replyTo
     * @throws NatsException
     */
    public function publish(
        string $subject,
        Message $message = new Message(),
        ?string $replyTo = null,
        ?Cancellation $cancellation = null,
    ): void {
        $connection = $this->connection($cancellation);

        if ($message->headers !== null && \count($message->headers) > 0 && !$connection->info()->allowHeaders) {
            throw Exception\FeatureIsNotSupported::forHeaders($connection->info()->serverVersion);
        }

        $connection->execute(Internal\Command::pub($subject, $message, $replyTo));
    }

    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $queueGroup
     * @param positive-int $bufferSize
     * @return Iterator<Delivery>
     * @throws NatsException
     */
    public function subscribeIterator(
        string $subject,
        ?string $queueGroup = null,
        ?Cancellation $cancellation = null,
        int $bufferSize = self::DEFAULT_DELIVERY_BUFFER_SIZE,
    ): Iterator {
        /** @var Pipeline\Queue<Delivery> $queue */
        $queue = new Pipeline\Queue(bufferSize: $bufferSize);

        $subscription = $this->subscribe(
            subject: $subject,
            /** @phpstan-ignore argument.type */
            handler: static fn(mixed $delivery): bool => Iter\push($queue, $delivery),
            queueGroup: $queueGroup,
            cancellation: $cancellation,
        );

        return Iter\PipelineIterator::fromQueue($queue, $subscription);
    }

    /**
     * @param non-empty-string $subject
     * @param callable(Delivery, Subscription): void $handler
     * @param ?non-empty-string $queueGroup
     * @param positive-int $bufferSize
     * @throws NatsException
     * @throws \Throwable
     */
    public function subscribe(
        string $subject,
        callable $handler,
        ?string $queueGroup = null,
        int $bufferSize = 1_000,
        ?Cancellation $cancellation = null,
    ): Subscription {
        $subscriptionId = $this->subscriptionIdGenerator->nextId();
        $unsubscribe = $this->unsubscribe(...);

        $handler = new SubscriptionHandler(
            unsubscribe: static function () use ($subscriptionId, $unsubscribe): void {
                $unsubscribe($subscriptionId);
            },
            handler: $handler,
            bufferSize: $bufferSize,
        );

        $this->subscribers[$subscriptionId] = [$handler->push(...), $subscription = $handler->subscription];

        try {
            $this->connection($cancellation)->execute(Internal\Command::sub($subject, $subscriptionId, $queueGroup));
        } catch (\Throwable $e) {
            unset($this->subscribers[$subscriptionId]);

            throw $e;
        }

        return $subscription;
    }

    /**
     * @param non-empty-string $subject
     */
    public function request(
        string $subject,
        Message $message = new Message(),
        ?Cancellation $cancellation = null,
    ): Delivery {
        $subscribe = $this->subscribeCallback(...);
        $this->rpc ??= async(static function () use (
            $subscribe,
            $cancellation,
        ): Rpc\Handler {
            $handler = new Rpc\Handler();
            $handler->setup($subscribe, $cancellation);

            return $handler;
        });

        return $this->rpc
            ->await($cancellation)
            ->request($subject, $message, $this)
            ->await($cancellation);
    }

    public function createService(Micro\ServiceConfig $config): Micro\Service
    {
        $identity = new Micro\ServiceIdentity(
            name: $config->name,
            id: Id\generateUniqueId(),
            version: $config->version,
            metadata: $config->metadata,
        );

        return new Micro\Service(
            nc: $this,
            identity: $identity,
            config: $config,
            encoder: $this->encoder,
        );
    }

    /**
     * @internal
     */
    public function toJetStreamDelivery(Delivery $delivery): JetStreamDelivery
    {
        return new JetStreamDelivery(
            message: $delivery->message,
            subject: $delivery->subject,
            acks: $this->acks,
            metadata: $delivery->replyTo !== null ? Metadata::parse($delivery->replyTo) : null,
            replyTo: $delivery->replyTo,
        );
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        $this->disconnect(static fn(Subscription $subscription) => $subscription->stop($cancellation), $cancellation);
    }

    public function drain(?Cancellation $cancellation = null): void
    {
        $this->disconnect(static fn(Subscription $subscription) => $subscription->drain($cancellation), $cancellation);
    }

    public function __destruct()
    {
        if (\PHP_VERSION_ID >= 80400) {
            $this->stop();
        }
    }

    /**
     * @param non-empty-string $subject
     * @param callable(Delivery): void $handler
     * @return \Closure(?Cancellation=): void
     * @throws NatsException
     * @throws \Throwable
     */
    private function subscribeCallback(
        string $subject,
        callable $handler,
        ?Cancellation $cancellation = null,
    ): \Closure {
        $subscriptionId = $this->subscriptionIdGenerator->nextId();

        $this->subscribers[$subscriptionId] = [
            static function (Delivery $delivery) use ($handler): bool {
                $handler($delivery);

                return true;
            },
            null,
        ];

        try {
            $this->connection($cancellation)->execute(Internal\Command::sub($subject, $subscriptionId));
        } catch (\Throwable $e) {
            unset($this->subscribers[$subscriptionId]);

            throw $e;
        }

        return function (?Cancellation $cancellation = null) use ($subscriptionId): void {
            $this->unsubscribe($subscriptionId, $cancellation);
        };
    }

    /**
     * @param non-empty-string $sid
     * @throws NatsException
     */
    private function unsubscribe(string $sid, ?Cancellation $cancellation = null): void
    {
        if (!isset($this->subscribers[$sid])) {
            return;
        }

        $this->connection($cancellation)->execute(Internal\Command::unsub($sid));
        unset($this->subscribers[$sid]);
    }

    private function invokeSubscriber(Hooks\MessageReceived $event): void
    {
        [$subscriber, $subscription] = $this->subscribers[$event->sid] ?? [static fn(): bool => true, null];

        $pushed = $subscriber(
            new Delivery(
                reply: $this->publish(...),
                subject: $event->subject,
                replyTo: $event->replyTo,
                message: new Message(
                    payload: $event->payload,
                    headers: $event->headers,
                ),
            ),
        );
        if (!$pushed) {
            $subscription?->stop();
        }
    }

    /**
     * @param \Closure(Subscription): void $do
     */
    private function disconnect(
        \Closure $do,
        ?Cancellation $cancellation = null,
    ): void {
        $connection = $this->connection?->await($cancellation);
        if ($connection === null) {
            return;
        }

        /** @var ?Subscription $subscription */
        foreach ($this->subscribers as [$_, $subscription]) {
            if ($subscription !== null) {
                $do($subscription);
            }
        }

        $this->rpc?->await($cancellation)?->shutdown($cancellation);

        $this->rpc = null;
        $this->connection = null;
        $connection->close();
    }

    private function connection(?Cancellation $cancellation = null): Connection\Connection
    {
        $connectionFactory = $this->connectionFactory;
        $invokeSubscriber = $this->invokeSubscriber(...);

        $this->connection ??= async(static function () use (
            $connectionFactory,
            $invokeSubscriber,
        ): Connection\Connection {
            $connection = $connectionFactory->connect();
            $connection->hooks()->onMessage($invokeSubscriber);

            return $connection;
        });

        return $this->connection->await($cancellation);
    }
}
