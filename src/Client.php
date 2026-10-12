<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Pipeline;
use Revolt\EventLoop;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Internal\Connection;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\Iter;
use Thesis\Nats\Internal\Protocol;
use Thesis\Nats\Internal\Rpc;
use Thesis\Nats\Internal\Subscription\Subscriber;
use Thesis\Nats\Internal\Subscription\SubscriptionHandler;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\Acks;
use Thesis\Nats\JetStream\Metadata;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Json\NativeEncoder;
use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Serialization\ValinorSerializer;
use function Amp\async;
use function Amp\weakClosure;

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

    /**
     * The established connection, kept separately from the future so that it can be closed
     * synchronously in the destructor, where awaiting is not allowed.
     */
    private ?Connection\Connection $activeConnection = null;

    /** @var ?Future<Rpc\Handler> */
    private ?Future $rpc = null;

    /** @var array<non-empty-string, Subscriber> */
    private array $subscribers = [];

    /** @var list<\Closure(\Throwable): void> */
    private array $errorCallbacks = [];

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

        $connection->execute(Internal\Command::pub($subject, $message, $replyTo), $cancellation);
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

        // The iterator ends the way the subscription does: cleanly after stop() or drain(), with the error
        // after a failure, e.g. the connection closed by the server. Otherwise the consumer would wait forever.
        async(static function () use ($queue, $subscription): void {
            try {
                $subscription->awaitCompletion();
            } catch (\Throwable $e) {
                if (!$queue->isComplete()) {
                    $queue->error($e);
                }

                return;
            }

            if (!$queue->isComplete()) {
                $queue->complete();
            }
        })->ignore();

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

        $this->subscribers[$subscriptionId] = new Subscriber(
            subject: $subject,
            queueGroup: $queueGroup,
            push: $handler->push(...),
            subscription: $subscription = $handler->subscription,
        );

        try {
            $this->sub($subject, $subscriptionId, $queueGroup, $cancellation);
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
        $this->rpc ??= async(function () use ($subscribe): Rpc\Handler {
            $handler = new Rpc\Handler();

            try {
                // The inbox subscription is shared by all requests, so the caller's cancellation must not interrupt it.
                $handler->setup($subscribe);
            } catch (\Throwable $e) {
                // Forget the failed setup so that the next request tries again.
                $this->rpc = null;

                throw $e;
            }

            return $handler;
        });

        return $this->rpc
            ->await($cancellation)
            ->request($subject, $message, $this, $cancellation);
    }

    /**
     * Registers a callback for the server errors (-ERR) that cannot be attributed to a particular call,
     * e.g. a permission violation of a publish in non-verbose mode, a slow consumer, or a stale connection.
     * The errors that make the server close the connection are additionally passed to the active subscriptions
     * and pending requests.
     *
     * @param callable(\Throwable): void $callback
     */
    public function onError(callable $callback): void
    {
        $this->errorCallbacks[] = $callback(...);
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
        // Awaiting is not allowed here: the destructor may run outside of the event loop (e.g. on shutdown).
        [$connection, $this->activeConnection] = [$this->activeConnection, null];
        $this->connection = null;
        $this->rpc = null;
        $this->subscribers = [];

        $connection?->close();
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

        $this->subscribers[$subscriptionId] = new Subscriber(
            subject: $subject,
            queueGroup: null,
            push: static function (Delivery $delivery) use ($handler): bool {
                $handler($delivery);

                return true;
            },
        );

        try {
            $this->sub($subject, $subscriptionId, cancellation: $cancellation);
        } catch (\Throwable $e) {
            unset($this->subscribers[$subscriptionId]);

            throw $e;
        }

        return function (?Cancellation $cancellation = null) use ($subscriptionId): void {
            $this->unsubscribe($subscriptionId, $cancellation);
        };
    }

    /**
     * @param non-empty-string $subject
     * @param non-empty-string $sid
     * @param ?non-empty-string $queueGroup
     * @throws NatsException
     */
    private function sub(string $subject, string $sid, ?string $queueGroup = null, ?Cancellation $cancellation = null): void
    {
        $connection = $this->connection($cancellation);

        try {
            $connection->execute(Internal\Command::sub($subject, $sid, $queueGroup), $cancellation);
        } catch (CancelledException $e) {
            // In verbose mode the SUB has already been sent when the wait for +OK is cancelled:
            // the server-side subscription must not outlive the failed call.
            async($connection->execute(...), Internal\Command::unsub($sid))->ignore();

            throw $e;
        }
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

        $this->connection($cancellation)->execute(Internal\Command::unsub($sid), $cancellation);
        unset($this->subscribers[$sid]);
    }

    private function invokeSubscriber(Hooks\MessageReceived $event): void
    {
        $subscriber = $this->subscribers[$event->sid] ?? null;
        if ($subscriber === null) {
            return;
        }

        $pushed = ($subscriber->push)(
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
            $subscriber->subscription?->stop();
        }
    }

    /**
     * Routes a server error that was not an acknowledgement of a command, the same way nats.go does:
     * a subscription permission violation fails that subscription only, a publish permission violation
     * is reported to the error callbacks, anything else is fatal and the connection is closed.
     * A fatal error is reported once the connection is torn down, see onClose in connection().
     *
     * @return ?\Throwable the error the connection is closed with
     */
    private function handleError(Hooks\ErrorReceived $event, Connection\Connection $connection): ?\Throwable
    {
        $e = $event->error->toException();

        if ($e instanceof Exception\PermissionViolated) {
            if (!$this->failSubscriptions($event->error, $e)) {
                $this->reportError($e);
            }

            return null;
        }

        $connection->close();

        return $e;
    }

    /**
     * @return bool whether the error was attributed to at least one subscription
     */
    private function failSubscriptions(Protocol\Err $error, \Throwable $e): bool
    {
        $violated = $error->violatedSubscription();
        if ($violated === null) {
            return false;
        }

        [$subject, $queueGroup] = $violated;
        $failed = false;

        foreach ($this->subscribers as $subscriber) {
            if ($subscriber->subscription !== null && $subscriber->subject === $subject && $subscriber->queueGroup === $queueGroup) {
                async($subscriber->subscription->error(...), $e)->ignore();
                $failed = true;
            }
        }

        return $failed;
    }

    private function reportError(\Throwable $e): void
    {
        foreach ($this->errorCallbacks as $callback) {
            EventLoop::queue($callback, $e);
        }
    }

    /**
     * Shuts the client down from the user side: the subscriptions are completed over the still open connection
     * (drain needs it to receive the remaining messages), then the connection is closed.
     *
     * @param \Closure(Subscription): void $do
     */
    private function disconnect(\Closure $do, ?Cancellation $cancellation = null): void
    {
        $connection = $this->connection !== null ? self::awaitOrNull($this->connection, $cancellation) : null;
        if ($connection === null) {
            return;
        }

        $completes = [];

        foreach ($this->subscribers as $subscriber) {
            if ($subscriber->subscription !== null) {
                $completes[] = async($do, $subscriber->subscription);
            }
        }

        $rpc = $this->rpc !== null ? self::awaitOrNull($this->rpc, $cancellation) : null;

        if ($rpc !== null) {
            $completes[] = async($rpc->shutdown(...), $cancellation);
        }

        try {
            Future\await($completes, $cancellation);
        } finally {
            $this->subscribers = [];
            $this->rpc = null;
            $this->connection = null;
            $this->activeConnection = null;
            $connection->close();
        }
    }

    /**
     * Tears the client down once the connection has been closed from the outside: by the server (e.g. a kick
     * or a restart) or because of a fatal server error. Nothing is sent over the connection, it is gone and so
     * are the server-side subscriptions: the subscriptions and the pending requests are only failed locally.
     *
     * @param ?\Throwable $error the fatal server error that closed the connection, if any
     */
    private function closed(Connection\Connection $connection, ?\Throwable $error): void
    {
        if ($this->activeConnection !== $connection) {
            // Closed by stop() or drain(), which have already torn everything down, or superseded by a new connection.
            return;
        }

        // Detached before anything is failed, so that the subscriptions find nothing to unsubscribe from
        // and the next call establishes a new connection instead of reusing the closed one.
        [$subscribers, $this->subscribers] = [$this->subscribers, []];
        [$rpc, $this->rpc] = [$this->rpc, null];
        $this->connection = null;
        $this->activeConnection = null;

        $e = $error ?? new ConnectionWasClosed();
        $completes = [];

        foreach ($subscribers as $subscriber) {
            if ($subscriber->subscription !== null) {
                $completes[] = async($subscriber->subscription->error(...), $e);
            }
        }

        // A pending setup has no requests to fail yet: its failure is thrown to the request that started it.
        if ($rpc !== null && $rpc->isComplete()) {
            $completes[] = async(static function () use ($rpc, $e): void {
                self::awaitOrNull($rpc)?->shutdown(reason: $e);
            });
        }

        try {
            // A subscription may refuse to fail (e.g. an onComplete callback throws): the others must still be failed,
            // and nothing may escape to the event loop, which has no one to catch it.
            Future\awaitAll($completes);
        } finally {
            if ($error !== null) {
                // Reported after the teardown, so that the callback observes the subscriptions already failed
                // and the next call establishing a new connection.
                $this->reportError($error);
            }
        }
    }

    /**
     * Awaits the future, returning null if it has failed: the failure has already been reported
     * to the caller who initiated the operation, and there is nothing to clean up.
     *
     * @template T
     * @param Future<T> $future
     * @return ?T
     * @throws \Throwable if the await was interrupted by $cancellation while the future is still pending
     */
    private static function awaitOrNull(Future $future, ?Cancellation $cancellation = null): mixed
    {
        try {
            return $future->await($cancellation);
        } catch (\Throwable $e) {
            if (!$future->isComplete()) {
                throw $e;
            }

            return null;
        }
    }

    private function connection(?Cancellation $cancellation = null): Connection\Connection
    {
        $this->connection ??= async(weakClosure(function (): Connection\Connection {
            try {
                $connection = $this->connectionFactory->connect();
            } catch (\Throwable $e) {
                // Forget the failed attempt so that the next call tries to connect again.
                $this->connection = null;

                throw $e;
            }

            $this->activeConnection = $connection;

            /** @var ?\Throwable $reason the fatal server error that closed the connection */
            $reason = null;

            $connection->hooks()->onMessage($this->invokeSubscriber(...));
            $connection->hooks()->onError(function (Hooks\ErrorReceived $event) use ($connection, &$reason): void {
                $reason ??= $this->handleError($event, $connection);
            });
            $connection->hooks()->onClose(function () use ($connection, &$reason): void {
                $this->closed($connection, $reason);
            });

            return $connection;
        }));

        return $this->connection->await($cancellation);
    }
}
