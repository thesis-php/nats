<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Future;
use Thesis\Nats\Internal\Connection;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\Rpc;
use function Amp\async;

/**
 * @api
 */
final class Client
{
    private readonly Connection\ConnectionFactory $connectionFactory;

    /** @var ?Future<Connection\Connection> */
    private ?Future $connectionFuture = null;

    private ?Connection\Connection $connection = null;

    /** @var array<non-empty-string, callable(Delivery, self): void> */
    private array $subscribers = [];

    /** @var ?Future<Rpc\Handler> */
    private ?Future $rpcFuture = null;

    private ?Rpc\Handler $rpc = null;

    public function __construct(
        private readonly Config $config,
        ?Connection\ConnectionFactory $connectionFactory = null,
    ) {
        $this->connectionFactory = $connectionFactory ?: Connection\SocketConnectionFactory::fromConfig($this->config);
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
     * @param non-empty-string $subject
     * @param ?non-empty-string $replyTo
     */
    public function publish(
        string $subject,
        Message $message = new Message(),
        ?string $replyTo = null,
    ): void {
        $connection = $this->connection();
        if (\count($message->headers) > 0 && !$connection->info()->allowHeaders) {
            throw Exception\FeatureIsNotSupported::forHeaders($connection->info()->serverVersion);
        }

        $connection->execute(Internal\Command::pub($subject, $message, $replyTo));
    }

    /**
     * @param non-empty-string $subject
     * @param callable(Delivery, self): void $handler
     * @param ?non-empty-string $queueGroup
     * @return non-empty-string
     */
    public function subscribe(
        string $subject,
        callable $handler,
        ?string $queueGroup = null,
    ): string {
        $subscriptionId = Id\generateSubscriptionId();
        $this->subscribers[$subscriptionId] = $handler;

        $this->connection()->execute(Internal\Command::sub($subject, $subscriptionId, $queueGroup));

        return $subscriptionId;
    }

    /**
     * @param non-empty-string $subject
     */
    public function request(
        string $subject,
        Message $message = new Message(),
    ): Delivery {
        if ($this->rpc === null) {
            $this->rpcFuture ??= async(function (): Rpc\Handler {
                $handler = new Rpc\Handler($this);
                $handler->setup();

                return $handler;
            });

            try {
                $this->rpc = $this->rpcFuture->await();
            } finally {
                $this->rpcFuture = null;
            }
        }

        return $this->rpc
            ->request($subject, $message)
            ->await();
    }

    /**
     * @param non-empty-string $sid
     */
    public function unsubscribe(string $sid): void
    {
        $this->connection()->execute(Internal\Command::unsub($sid));
    }

    public function disconnect(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            $this->rpc?->shutdown();

            $this->connection->close();
        } finally {
            $this->connection = null;
            $this->subscribers = [];
        }
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
                    headers: $event->headers?->keyvals ?: [],
                    status: $event->headers?->status !== null ? Status::from($event->headers->status) : null,
                ),
            ),
            $this,
        );
    }

    private function connection(): Connection\Connection
    {
        $this->connectionFuture?->await();

        if ($this->connection !== null) {
            return $this->connection;
        }

        $this->connectionFuture ??= async($this->connectionFactory->connect(...));

        try {
            $this->connection = $this->connectionFuture->await();
            $this->connection->hooks()->onMessage($this->invokeSubscriber(...));
        } finally {
            $this->connectionFuture = null;
        }

        return $this->connection;
    }
}
