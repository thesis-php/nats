<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Cancellation;
use Amp\Pipeline;
use Thesis\Nats\Internal\Connection;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\Rpc;
use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Serialization\ValinorSerializer;
use Thesis\Sync;
use function Amp\weakClosure;

/**
 * @api
 */
final class Client
{
    private readonly Connection\ConnectionFactory $connectionFactory;

    /** @var ?Sync\Once<Connection\Connection> */
    private ?Sync\Once $connection = null;

    /** @var ?Sync\Once<Rpc\Handler> */
    private ?Sync\Once $rpc = null;

    /** @var array<non-empty-string, callable(Delivery, self, non-empty-string): void> */
    private array $subscribers = [];

    private readonly Id\SubscriptionIdGenerator $subscriptionIdGenerator;

    public function __construct(
        private readonly Config $config,
        private readonly Serializer $serializer = new ValinorSerializer(),
    ) {
        $this->connectionFactory = Connection\SocketConnectionFactory::fromConfig($this->config);
        $this->subscriptionIdGenerator = new Id\SubscriptionIdGenerator();
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
            domain: $domain ?: $this->config->jetStreamDomain,
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
     * @return Iterator<Delivery>
     * @throws NatsException
     */
    public function subscribeIterator(
        string $subject,
        ?string $queueGroup = null,
        ?Cancellation $cancellation = null,
    ): Iterator {
        /** @var Pipeline\Queue<Delivery> $queue */
        $queue = new Pipeline\Queue();

        $subscriptionId = $this->subscribe(
            subject: $subject,
            handler: $queue->push(...),
            queueGroup: $queueGroup,
            cancellation: $cancellation,
        );

        return new Internal\QueueIterator(
            iterator: $queue->iterate(),
            complete: function (?Cancellation $cancellation = null) use ($subscriptionId, $queue): void {
                $this->unsubscribe($subscriptionId, $cancellation);
                $queue->complete();
            },
            cancel: function (\Throwable $e, ?Cancellation $cancellation = null) use ($subscriptionId, $queue): void {
                $this->unsubscribe($subscriptionId, $cancellation);
                $queue->error($e);
            },
        );
    }

    /**
     * @param non-empty-string $subject
     * @param callable(Delivery, self, non-empty-string): void $handler
     * @param ?non-empty-string $queueGroup
     * @return non-empty-string
     * @throws NatsException
     */
    public function subscribe(
        string $subject,
        callable $handler,
        ?string $queueGroup = null,
        ?Cancellation $cancellation = null,
    ): string {
        $subscriptionId = $this->subscriptionIdGenerator->nextId();
        $this->subscribers[$subscriptionId] = $handler;

        $this->connection($cancellation)->execute(Internal\Command::sub($subject, $subscriptionId, $queueGroup));

        return $subscriptionId;
    }

    /**
     * @param non-empty-string $subject
     */
    public function request(
        string $subject,
        Message $message = new Message(),
        ?Cancellation $cancellation = null,
    ): Delivery {
        $this->rpc ??= new Sync\Once(weakClosure(function (): Rpc\Handler {
            $handler = new Rpc\Handler($this);
            $handler->setup();

            return $handler;
        }));

        return $this->rpc
            ->await($cancellation)
            ->request($subject, $message)
            ->await($cancellation);
    }

    /**
     * @param non-empty-string $sid
     * @throws NatsException
     */
    public function unsubscribe(string $sid, ?Cancellation $cancellation = null): void
    {
        $this->connection($cancellation)->execute(Internal\Command::unsub($sid));
        unset($this->subscribers[$sid]);
    }

    public function disconnect(?Cancellation $cancellation = null): void
    {
        $connection = $this->connection?->await($cancellation);
        if ($connection === null) {
            return;
        }

        $this->connection = null;
        $connection->close();
    }

    public function __destruct()
    {
        if (\PHP_VERSION_ID >= 80400) {
            $this->disconnect();
        }
    }

    private function invokeSubscriber(Hooks\MessageReceived $event): void
    {
        $subscriber = $this->subscribers[$event->sid] ?? static fn() => null;
        $subscriber(
            new Delivery(
                reply: $this->publish(...),
                subject: $event->subject,
                replyTo: $event->replyTo,
                message: new Message(
                    payload: $event->payload,
                    headers: $event->headers,
                ),
            ),
            $this,
            $event->sid,
        );
    }

    private function connection(?Cancellation $cancellation = null): Connection\Connection
    {
        $this->connection ??= new Sync\Once(weakClosure(function (): Connection\Connection {
            $connection = $this->connectionFactory->connect();
            $connection->hooks()->onMessage($this->invokeSubscriber(...));

            return $connection;
        }));

        return $this->connection->await($cancellation);
    }
}
