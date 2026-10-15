<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use Revolt\EventLoop;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Exception\ServerIsNotAvailable;
use Thesis\Nats\Internal\Id;
use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\delay;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

#[CoversClass(Client::class)]
final class ClientTest extends NatsTestCase
{
    public function testPublishSubscribe(): void
    {
        $client = $this->client();

        /** @var DeferredFuture<Delivery> $deferred */
        $deferred = new DeferredFuture();

        $client->subscribe('events.*', $deferred->complete(...));

        $client->publish('events.happens', new Message('ok'));

        $delivery = $deferred->getFuture()->await();
        self::assertEquals('events.happens', $delivery->subject);
        self::assertEquals('ok', $delivery->message->payload);

        $client->stop();
    }

    public function testPublishSubscribeIterator(): void
    {
        $client = $this->client();

        $deliveries = $client->subscribeIterator('events.*');
        $client->publish('events.happens', new Message('ok'));

        foreach ($deliveries as $delivery) {
            self::assertEquals('events.happens', $delivery->subject);
            self::assertEquals('ok', $delivery->message->payload);

            $deliveries->stop();
        }
    }

    public function testRequestReply(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $client->subscribe("{$id}.*", static function (Delivery $delivery): void {
            $delivery->reply(new Message('ok'));
        });

        self::assertEquals('ok', $client->request("{$id}.happens", new Message('Are you ok?'))->message->payload);

        $client->stop();
    }

    public function testFailedAndCancelledRequestsAreForgotten(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        // Never answered: the request can end only by the cancellation.
        $client->subscribe("{$id}.ignored", static fn() => null);
        $client->subscribe("{$id}.answered", static function (Delivery $delivery): void {
            $delivery->reply(new Message('ok'));
        });

        try {
            $client->request("{$id}.ignored", new Message('Are you ok?'), new TimeoutCancellation(0.1));
            self::fail('Nobody answers the request, it must be cancelled.');
        } catch (CancelledException) {
        }

        try {
            $client->request("{$id}.nobody", new Message('Are you ok?'));
            self::fail('Nobody listens to the subject, the request must fail.');
        } catch (RequestHasNoResponders) {
        }

        self::assertSame('ok', $client->request("{$id}.answered", new Message('Are you ok?'))->message->payload);
        self::assertSame(0, self::pendingRequests($client));

        $client->stop();
    }

    public function testUnsubscribe(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $client->subscribe("{$id}.*", static fn() => null)->stop();

        self::expectException(RequestHasNoResponders::class);
        $client->request("{$id}.happens", new Message('Are you ok?'));
    }

    public function testStopSubscription(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $count = 0;

        $subscription = $client->subscribe("{$id}.*", static function (Delivery $delivery) use (&$count): void {
            if ($count === 0) {
                // Let the subscription accumulate messages in its local queue buffer.
                delay(0.1);
            }

            ++$count;
        });

        for ($i = 0; $i < 10; ++$i) {
            $client->publish("{$id}.{$i}", new Message("{$i}"));
        }

        delay(0.1);
        $subscription->stop();
        $subscription->awaitCompletion();

        self::assertSame(1, $count);
    }

    public function testDrainSubscription(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $count = 0;

        $subscription = $client->subscribe("{$id}.*", static function (Delivery $delivery) use (&$count): void {
            if ($count === 0) {
                // Let the subscription accumulate messages in its local queue buffer.
                delay(0.1);
            }

            ++$count;
        });

        for ($i = 0; $i < 10; ++$i) {
            $client->publish("{$id}.{$i}", new Message("{$i}"));
        }

        delay(0.1);
        $subscription->drain();
        $subscription->awaitCompletion();

        self::assertSame(10, $count);
    }

    public function testSuspendExceptionalSubscription(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $subscription = $client->subscribe("{$id}.*", static function (): void {
            throw new \RuntimeException('Exception in test.');
        });

        $client->publish("{$id}.x");

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Exception in test.');
        $subscription->awaitCompletion();
    }

    #[DoesNotPerformAssertions]
    public function testSuspendSubscriptionOnClientDisconnect(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $subscription = $client->subscribe("{$id}.*", static function (): void {});
        $client->stop();
        $subscription->awaitCompletion();
    }

    public function testSubscriptionsAndRequestsFailWhenServerClosesConnection(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->clientNamed($name);

        // The teardown runs in the event loop, where an escaped exception has no one to catch it.
        $loopErrors = [];
        $errorHandler = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (\Throwable $e) use (&$loopErrors): void {
            $loopErrors[] = $e;
        });

        try {
            $id = Id\generateUniqueId();

            $subscription = $client->subscribe("{$id}.*", static fn() => null);
            $iterator = $client->subscribeIterator("{$id}.*");
            // Never answered: the subscription above receives the request and ignores it.
            $request = async($client->request(...), "{$id}.request", new Message('Are you ok?'));

            $this->kick($name);

            try {
                $subscription->awaitCompletion(new TimeoutCancellation(5));
                self::fail('The connection was closed by the server, the subscription must fail.');
            } catch (ConnectionWasClosed) {
            }

            try {
                $request->await(new TimeoutCancellation(5));
                self::fail('The connection was closed by the server, the request must fail.');
            } catch (ConnectionWasClosed) {
            }

            try {
                foreach ($iterator as $delivery) {
                    // The request above is the only message the iterator receives before the connection is closed.
                    self::assertSame("{$id}.request", $delivery->subject);
                }

                self::fail('The connection was closed by the server, the iterator must fail.');
            } catch (ConnectionWasClosed) {
            }

            self::assertSame([], $loopErrors, implode("\n", array_map(static fn(\Throwable $e): string => (string) $e, $loopErrors)));

            // The client is usable again: the next call establishes a new connection.
            /** @var DeferredFuture<Delivery> $deferred */
            $deferred = new DeferredFuture();

            $client->subscribe("{$id}.*", $deferred->complete(...));
            $client->publish("{$id}.happens", new Message('ok'));

            self::assertSame('ok', $deferred->getFuture()->await(new TimeoutCancellation(5))->message->payload);

            $client->stop();
        } finally {
            EventLoop::setErrorHandler($errorHandler);
        }
    }

    public function testPublishReconnectsAfterFailedConnection(): void
    {
        $address = self::reserveFreePort();
        $client = $this->clientWithUrls([$address]);

        try {
            $client->publish('events.happens', new Message('ok'));
            self::fail('The server is not available yet, publish must fail.');
        } catch (ServerIsNotAvailable) {
        }

        // The server becomes available on the same address: the client must establish a new connection.
        $stopProxy = self::proxyToNats($address, $this->natsAddress());

        try {
            $id = Id\generateUniqueId();

            /** @var DeferredFuture<Delivery> $deferred */
            $deferred = new DeferredFuture();

            $client->subscribe("{$id}.*", $deferred->complete(...));
            $client->publish("{$id}.happens", new Message('ok'));

            $delivery = $deferred->getFuture()->await(new TimeoutCancellation(5));
            self::assertEquals("{$id}.happens", $delivery->subject);
            self::assertEquals('ok', $delivery->message->payload);

            $client->stop();
        } finally {
            $stopProxy();
        }
    }

    public function testRequestReconnectsAfterFailedConnection(): void
    {
        $address = self::reserveFreePort();
        $client = $this->clientWithUrls([$address]);

        $id = Id\generateUniqueId();

        try {
            $client->request("{$id}.happens", new Message('Are you ok?'), new TimeoutCancellation(5));
            self::fail('The server is not available yet, request must fail.');
        } catch (ServerIsNotAvailable) {
        }

        $stopProxy = self::proxyToNats($address, $this->natsAddress());

        try {
            $client->subscribe("{$id}.*", static function (Delivery $delivery): void {
                $delivery->reply(new Message('ok'));
            });

            $delivery = $client->request("{$id}.happens", new Message('Are you ok?'), new TimeoutCancellation(5));
            self::assertEquals('ok', $delivery->message->payload);

            $client->stop();
        } finally {
            $stopProxy();
        }
    }

    public function testConnectFailsWhenServerAcceptsAndStaysSilent(): void
    {
        $server = listen('127.0.0.1:0');
        $address = $server->getAddress()->toString();
        \assert($address !== '');

        // The peer accepts the connection, but never sends INFO.
        $accepted = async($server->accept(...));

        $client = $this->clientWithUrls([$address]);
        $startedAt = microtime(true);

        try {
            $client->publish('events.happens', new Message('ok'), cancellation: new TimeoutCancellation(5));
            self::fail('The server never sends INFO, publish must fail.');
        } catch (ServerIsNotAvailable) {
        }

        // The connection timeout of the client is 1 second.
        self::assertLessThan(3, microtime(true) - $startedAt);

        $peer = $accepted->await(new TimeoutCancellation(1));
        self::assertNotNull($peer);
        // The client has closed the socket it gave up on.
        self::assertNull($peer->read(new TimeoutCancellation(1)));

        $server->close();
    }

    public function testConnectFailsWhenServerDoesNotAnswerHandshake(): void
    {
        $proxy = PausableProxy::start($this->natsAddress());

        try {
            // The server sends INFO, but never sees CONNECT and the PING after it, so it never answers with PONG.
            $proxy->pause();

            $client = $this->clientWithUrls([$proxy->address]);
            $startedAt = microtime(true);

            try {
                $client->publish('events.happens', new Message('ok'), cancellation: new TimeoutCancellation(5));
                self::fail('The server never answers the handshake, publish must fail.');
            } catch (ServerIsNotAvailable) {
            }

            self::assertLessThan(3, microtime(true) - $startedAt);

            // Once the server answers again, the next call establishes a new connection.
            $proxy->resume();

            $id = Id\generateUniqueId();

            /** @var DeferredFuture<Delivery> $deferred */
            $deferred = new DeferredFuture();

            $client->subscribe("{$id}.*", $deferred->complete(...));
            $client->publish("{$id}.happens", new Message('ok'));

            self::assertSame('ok', $deferred->getFuture()->await(new TimeoutCancellation(5))->message->payload);

            $client->stop();
        } finally {
            $proxy->stop();
        }
    }

    #[DoesNotPerformAssertions]
    public function testClientWithFailedConnectionIsDestroyedQuietly(): void
    {
        $client = new Client(new Config(urls: [self::reserveFreePort()], connectionTimeout: 1));

        try {
            $client->publish('events.happens', new Message('ok'));
            self::fail('The server is not available, publish must fail.');
        } catch (ServerIsNotAvailable) {
        }

        unset($client);
        // The client participates in reference cycles, so its destructor runs only on cycle collection.
        gc_collect_cycles();
    }

    /**
     * @return non-empty-string an address on which nothing is listening at the moment
     */
    private static function reserveFreePort(): string
    {
        $server = listen('127.0.0.1:0');
        $address = $server->getAddress()->toString();
        $server->close();

        \assert($address !== '');

        return $address;
    }

    /**
     * Starts a tcp proxy forwarding every connection accepted on $listen to the real nats server at $upstream.
     *
     * @param non-empty-string $listen
     * @param non-empty-string $upstream
     * @return \Closure(): void stops the proxy
     */
    private static function proxyToNats(string $listen, string $upstream): \Closure
    {
        $server = listen($listen);

        $forward = static function (Socket $from, Socket $to): void {
            try {
                pipe($from, $to);
            } catch (\Throwable) {
            } finally {
                $from->close();
                $to->close();
            }
        };

        EventLoop::queue(static function () use (
            $server,
            $upstream,
            $forward,
        ): void {
            while (($client = $server->accept()) !== null) {
                $nats = connect($upstream);

                $future = async($forward, $client, $nats);
                $future->ignore();
                $future = async($forward, $nats, $client);
                $future->ignore();
            }
        });

        return $server->close(...);
    }
}
