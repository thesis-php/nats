<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Thesis\Nats\Exception\AuthorizationViolation;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Exception\PermissionViolated;
use Thesis\Nats\Exception\ReconnectBufferExceeded;
use Thesis\Nats\Exception\StaleConnection;
use Thesis\Nats\Internal\Connection\ReconnectingConnection;
use Thesis\Nats\Internal\Connection\ServerPool;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\Protocol;
use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

#[CoversClass(Client::class)]
#[CoversClass(ReconnectingConnection::class)]
#[CoversClass(ServerPool::class)]
final class ReconnectTest extends NatsTestCase
{
    /** The seed of the NKey user listed in docker/nats/server.conf. */
    private const string SEED = 'SUAD3LTGPJHBFPJMOTGX6OIF6YDL2FZ2IGYQIOFORBGSLGYSF2PJWGWGFU';

    /** A valid user seed unknown to the server. */
    private const string UNKNOWN_SEED = 'SUAHNDAT4XZP3JOFBU5STXVC7473S7UBIOSJBUETHPUFHAMZIW7PY73WUM';

    /** @var list<PausableProxy> */
    private array $proxies = [];

    /** @var list<Client> */
    private array $clients = [];

    /** @var list<non-empty-string> */
    private array $files = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stop();
        }

        foreach ($this->proxies as $proxy) {
            $proxy->kill();
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testSubscriptionsAndRequestsSurviveServerSideDisconnect(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->clientNamed($name);
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $reconnected = $this->watch($client, $events);
        $subject = Id\generateUniqueId();

        /** @var \ArrayObject<int, string> $received */
        $received = new \ArrayObject();
        $subscription = $client->subscribe("{$subject}.*", static function (Delivery $delivery) use ($received): void {
            $received[] = (string) $delivery->message->payload;
        });

        $responder = $this->otherClient();
        $responder->subscribe("{$subject}.rpc", static function (Delivery $delivery): void {
            $delivery->reply(new Message('pong'));
        });
        $responder->ping();

        // The request inbox is subscribed before the disconnect.
        self::assertSame('pong', $client->request("{$subject}.rpc", new Message('ping'), timeout: 5)->message->payload);

        $cid = $this->connectionId($name);
        $this->kick($name);
        $reconnected->await(new TimeoutCancellation(5));

        self::assertNotSame($cid, $this->connectionId($name));
        self::assertSame(['disconnect', 'reconnect'], $events->getArrayCopy());
        self::assertFalse($subscription->completed());

        // The same subscription and the same request inbox, restored on the new connection.
        $client->ping();
        $responder->publish("{$subject}.event", new Message('after'));
        self::assertSame('pong', $client->request("{$subject}.rpc", new Message('ping'), timeout: 5)->message->payload);
        self::assertContains('after', $received->getArrayCopy());
    }

    public function testPublishesAreBufferedWhileReconnecting(): void
    {
        $proxy = $this->proxy();
        $client = $this->clientVia($proxy, reconnectWait: 0.1);
        $disconnected = new DeferredFuture();
        $client->onDisconnect(static fn() => $disconnected->complete());
        $reconnected = $this->watch($client);
        $subject = Id\generateUniqueId();

        $observer = $this->otherClient();
        $deliveries = $observer->subscribeIterator($subject);
        $observer->ping();
        $client->connect();

        $proxy->kill();
        $disconnected->getFuture()->await(new TimeoutCancellation(5));

        // Nothing to send them to: they wait for the reconnect, in order.
        foreach (['one', 'two', 'three'] as $payload) {
            $client->publish($subject, new Message($payload));
        }

        $this->restart($proxy);
        $reconnected->await(new TimeoutCancellation(5));

        $received = [];
        foreach ($deliveries as $delivery) {
            $received[] = (string) $delivery->message->payload;

            if (\count($received) === 3) {
                $deliveries->stop();
            }
        }

        self::assertSame(['one', 'two', 'three'], $received);
    }

    public function testMissedPongsReportStaleConnection(): void
    {
        $proxy = $this->proxy();
        $config = $this->config();
        $client = $this->track(new Client(new Config(
            urls: [$proxy->address],
            connectionTimeout: 1,
            user: $config->user,
            password: $config->password,
            ping: 100,
            maxPings: 1,
            reconnectWait: 0.1,
        )));

        /** @var DeferredFuture<?\Throwable> $disconnected */
        $disconnected = new DeferredFuture();
        $client->onDisconnect(static function (?\Throwable $reason) use ($disconnected): void {
            if (!$disconnected->isComplete()) {
                $disconnected->complete($reason);
            }
        });
        $reconnected = $this->watch($client);
        $client->connect();

        // The PINGs no longer reach the server, so no PONG comes back.
        $proxy->pause();

        self::assertInstanceOf(StaleConnection::class, $disconnected->getFuture()->await(new TimeoutCancellation(5)));

        $proxy->resume();
        $reconnected->await(new TimeoutCancellation(5));
    }

    public function testReconnectWaitsUntilServerHasRestoredSubscriptions(): void
    {
        $proxy = $this->proxy();
        $client = $this->clientVia($proxy, reconnectWait: 0.1);
        $disconnected = new DeferredFuture();
        $client->onDisconnect(static fn() => $disconnected->complete());
        $reconnected = $this->watch($client);
        $subject = Id\generateUniqueId();
        $deliveries = $client->subscribeIterator($subject);
        $client->ping();

        $proxy->kill();
        $disconnected->getFuture()->await(new TimeoutCancellation(5));

        // The new connection is established, but the restored SUB (and the PING after it) is held back.
        $restarted = $this->restart($proxy);
        $held = $restarted->pauseBefore('SUB ');
        $held->await(new TimeoutCancellation(5));
        delay(0.3);
        self::assertFalse($reconnected->isComplete(), 'The server has not seen the subscriptions yet.');

        $restarted->resume();
        $reconnected->await(new TimeoutCancellation(5));

        // The reconnect callbacks promise active subscriptions: a message sent right away is not lost.
        $this->otherClient()->publish($subject, new Message('right after the reconnect'));

        foreach ($deliveries as $delivery) {
            self::assertSame('right after the reconnect', $delivery->message->payload);
            $deliveries->stop();
        }
    }

    public function testReconnectConfirmsAllowedSubscriptionsAfterForbiddenOne(): void
    {
        $proxy = $this->proxy();

        // May subscribe only under "allowed.>"; verbose, so the forbidden SUB fails the restore's execute at once.
        $client = $this->track(new Client(new Config(
            urls: [$proxy->address],
            verbose: true,
            user: 'restricted',
            password: 'Pswd1',
            reconnectWait: 0.1,
        )));
        $disconnected = new DeferredFuture();
        $client->onDisconnect(static fn() => $disconnected->complete());
        $reconnected = $this->watch($client);
        $client->connect();

        $proxy->kill();
        $disconnected->getFuture()->await(new TimeoutCancellation(5));

        // Both are buffered while reconnecting and sent in this order by the restore.
        $id = Id\generateUniqueId();
        $forbidden = $client->subscribe("forbidden.{$id}", static fn() => null);
        $allowed = $client->subscribeIterator("allowed.{$id}");

        // The forbidden SUB reaches the server and is rejected, the allowed one (and the PING after it) is held back.
        $restarted = $this->restart($proxy);
        $restarted->pauseBefore('SUB allowed.')->await(new TimeoutCancellation(5));

        delay(0.3);
        self::assertFalse($reconnected->isComplete(), 'The server has not seen the allowed subscription yet.');

        $restarted->resume();
        $reconnected->await(new TimeoutCancellation(5));

        // The forbidden one fails alone.
        try {
            $forbidden->awaitCompletion(new TimeoutCancellation(5));
            self::fail('The subscription is forbidden, it must fail.');
        } catch (PermissionViolated) {
        }

        $this->otherClient()->publish("allowed.{$id}", new Message('right after the reconnect'));

        foreach ($allowed as $delivery) {
            self::assertSame('right after the reconnect', $delivery->message->payload);
            $allowed->stop();
        }
    }

    public function testConnectWaitsForReconnect(): void
    {
        $proxy = $this->proxy();
        $client = $this->clientVia($proxy, reconnectWait: 0.1);
        $disconnected = new DeferredFuture();
        $client->onDisconnect(static fn() => $disconnected->complete());
        $client->connect();

        $proxy->kill();
        $disconnected->getFuture()->await(new TimeoutCancellation(5));

        try {
            $client->connect(new TimeoutCancellation(0.2));
            self::fail('The server is down, connect must not return.');
        } catch (CancelledException) {
        }

        $this->restart($proxy);
        $client->connect(new TimeoutCancellation(5));
        self::assertGreaterThan(0, $client->ping(new TimeoutCancellation(5)));
    }

    public function testPublishFailsOnceReconnectBufferIsFull(): void
    {
        $proxy = $this->proxy();
        $client = $this->clientVia($proxy, reconnectBufferSize: 64);
        $disconnected = new DeferredFuture();
        $client->onDisconnect(static fn() => $disconnected->complete());
        $client->connect();

        $proxy->kill();
        $disconnected->getFuture()->await(new TimeoutCancellation(5));

        $client->publish('events.small', new Message('fits'));

        self::expectException(ReconnectBufferExceeded::class);
        $client->publish('events.large', new Message(str_repeat('x', 64)));
    }

    public function testVerbosePublishAndPingWaitForReconnect(): void
    {
        $proxy = $this->proxy();
        $client = $this->clientVia($proxy, reconnectWait: 0.1, verbose: true);
        $disconnected = new DeferredFuture();
        $client->onDisconnect(static fn() => $disconnected->complete());
        $subject = Id\generateUniqueId();

        $observer = $this->otherClient();
        $deliveries = $observer->subscribeIterator($subject);
        $observer->ping();
        $client->connect();

        $proxy->kill();
        $disconnected->getFuture()->await(new TimeoutCancellation(5));

        // A verbose publish is acknowledged by the server, so it cannot be buffered.
        $publish = async($client->publish(...), $subject, new Message('acknowledged'));
        $ping = async($client->ping(...));
        delay(0.3);
        self::assertFalse($publish->isComplete());
        self::assertFalse($ping->isComplete());

        $this->restart($proxy);
        $publish->await(new TimeoutCancellation(5));
        $ping->await(new TimeoutCancellation(5));

        foreach ($deliveries as $delivery) {
            self::assertSame('acknowledged', $delivery->message->payload);
            $deliveries->stop();
        }
    }

    public function testAutoUnsubscribeLimitSurvivesReconnect(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->clientNamed($name);
        $reconnected = $this->watch($client);
        $subject = Id\generateUniqueId();
        $publisher = $this->otherClient();

        /** @var \ArrayObject<int, string> $received */
        $received = new \ArrayObject();
        $subscription = $client->subscribe($subject, static function (Delivery $delivery) use ($received): void {
            $received[] = (string) $delivery->message->payload;
        }, max: 3);
        $client->ping();

        $publisher->publish($subject, new Message('1'));
        $this->waitFor(static fn(): bool => \count($received) === 1);

        $this->kick($name);
        $reconnected->await(new TimeoutCancellation(5));
        $client->ping();

        // Two messages are left of the limit: the restored subscription is removed by the server after them.
        foreach (['2', '3', '4', '5'] as $payload) {
            $publisher->publish($subject, new Message($payload));
        }

        $subscription->awaitCompletion(new TimeoutCancellation(5));
        self::assertSame(['1', '2', '3'], $received->getArrayCopy());
    }

    public function testClientGivesUpOnceServersUseUpTheirReconnects(): void
    {
        $proxy = $this->proxy();
        $client = $this->clientVia($proxy, reconnectWait: 0.05, maxReconnects: 2);

        /** @var DeferredFuture<?\Throwable> $closed */
        $closed = new DeferredFuture();
        $client->onClose(static fn(?\Throwable $reason) => $closed->complete($reason));
        $errors = new \ArrayObject();
        $client->onError(static fn(\Throwable $e) => $errors->append($e));
        $subscription = $client->subscribe(Id\generateUniqueId(), static fn() => null);

        $proxy->kill();

        $reason = $closed->getFuture()->await(new TimeoutCancellation(5));
        self::assertInstanceOf(ConnectionWasClosed::class, $reason);
        self::assertStringContainsString('no server is left', $reason->getMessage());

        try {
            $subscription->awaitCompletion(new TimeoutCancellation(1));
            self::fail('The client has given up reconnecting, the subscription must fail.');
        } catch (ConnectionWasClosed $e) {
            self::assertSame($reason, $e);
        }

        // Running out of servers is not a server error.
        self::assertCount(0, $errors);
    }

    #[RequiresPhpExtension('sodium')]
    public function testRepeatedAuthorizationErrorStopsReconnecting(): void
    {
        $name = Id\generateUniqueId();
        $file = $this->file(self::SEED);
        $client = $this->track(new Client(new Config(
            urls: [$this->natsAddress()],
            clientName: $name,
            nkeyFile: $file,
            reconnectWait: 0.05,
            maxReconnects: null,
        )));

        /** @var DeferredFuture<?\Throwable> $closed */
        $closed = new DeferredFuture();
        $client->onClose(static fn(?\Throwable $reason) => $closed->complete($reason));
        $client->connect();

        // The credentials are revoked: every attempt is rejected the same way, retrying forever makes no sense.
        file_put_contents($file, self::UNKNOWN_SEED);
        $this->kick($name);

        self::assertInstanceOf(AuthorizationViolation::class, $closed->getFuture()->await(new TimeoutCancellation(5)));
    }

    public function testLameDuckModeMovesToAnotherServer(): void
    {
        $name = Id\generateUniqueId();
        $proxy = $this->proxy();
        $config = $this->config();

        // Both urls lead to the same server: the client starts with the first one and has the second one to move to.
        $client = $this->track(new Client(new Config(
            urls: [$this->natsAddress(), $proxy->address],
            user: $config->user,
            password: $config->password,
            clientName: $name,
            reconnectWait: 10,
            noRandomize: true,
        )));
        $lameDuck = new DeferredFuture();
        $client->onLameDuckMode(static fn() => $lameDuck->complete());
        $reconnected = $this->watch($client);
        $subject = Id\generateUniqueId();
        $deliveries = $client->subscribeIterator($subject);
        $client->ping();
        $cid = $this->connectionId($name);

        $this->lameDuck($name);

        $lameDuck->getFuture()->await(new TimeoutCancellation(5));
        // Right away, without the 10 seconds pause between the rounds of the reconnects.
        $reconnected->await(new TimeoutCancellation(2));
        self::assertNotSame($cid, $this->connectionId($name));

        $client->publish($subject, new Message('moved'));

        foreach ($deliveries as $delivery) {
            self::assertSame('moved', $delivery->message->payload);
            $deliveries->stop();
        }
    }

    public function testLameDuckModeWithNowhereToMoveOnlyNotifies(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->clientNamed($name);
        $lameDuck = new DeferredFuture();
        $client->onLameDuckMode(static fn() => $lameDuck->complete());
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $this->watch($client, $events);
        $client->connect();
        $cid = $this->connectionId($name);

        $this->lameDuck($name);

        $lameDuck->getFuture()->await(new TimeoutCancellation(5));
        delay(0.3);
        self::assertSame($cid, $this->connectionId($name));
        self::assertCount(0, $events);
    }

    public function testReconnectsToServerDiscoveredFromCluster(): void
    {
        $cluster = $this->clusterUrls();
        // Only the first server is configured, behind a proxy: the other one is known from its INFO only.
        $proxy = $this->proxy($cluster[0]);
        $client = $this->track(new Client(new Config(urls: [$proxy->address], reconnectWait: 0.1, noRandomize: true)));
        $reconnected = $this->watch($client);
        $subject = Id\generateUniqueId();
        $deliveries = $client->subscribeIterator($subject);
        $client->ping();

        $proxy->kill();
        $reconnected->await(new TimeoutCancellation(5));

        // The publisher is connected to the second server of the cluster: the message is routed to the restored subscription.
        $publisher = $this->track(new Client(new Config(urls: [$cluster[1] ?? self::fail('The cluster has a single server.')])));
        $publisher->publish($subject, new Message('routed'));

        foreach ($deliveries as $delivery) {
            self::assertSame('routed', $delivery->message->payload);
            $deliveries->stop();
        }
    }

    public function testServersAnnouncedAfterConnectAreReported(): void
    {
        $server = listen('127.0.0.1:0');
        $address = $server->getAddress()->toString();
        \assert($address !== '');

        $peer = async(static function () use ($server): void {
            $peer = $server->accept() ?? self::fail('The client has not connected.');
            $peer->write(new Protocol\ServerInfo(version: '2.12.0', serverId: 'fake', headers: true)->encode());

            $buffer = '';

            while (!str_contains($buffer, "PING\r\n")) {
                $buffer .= $peer->read() ?? self::fail('The client has closed the connection.');
            }

            // A server joined the cluster after the client had connected.
            $peer->write("PONG\r\n");
            $peer->write(new Protocol\ServerInfo(version: '2.12.0', serverId: 'fake', headers: true, connectUrls: ['127.0.0.1:4333'])->encode());
            $peer->read();
        });
        $peer->ignore();

        $client = $this->track(new Client(new Config(urls: [$address], connectionTimeout: 5)));

        /** @var DeferredFuture<non-empty-list<non-empty-string>> $discovered */
        $discovered = new DeferredFuture();
        $client->onServersDiscovered(static fn(array $urls) => $discovered->complete($urls));
        $client->connect();

        $urls = $discovered->getFuture()->await(new TimeoutCancellation(5));
        $expected = [$address, '127.0.0.1:4333'];
        sort($urls);
        sort($expected);
        self::assertSame($expected, $urls);

        $server->close();
    }

    /**
     * @param ?\ArrayObject<int, string> $events
     * @return Future<void> completed on the first reconnect
     */
    private function watch(Client $client, ?\ArrayObject $events = null): Future
    {
        /** @var DeferredFuture<void> $reconnected */
        $reconnected = new DeferredFuture();

        $client->onDisconnect(static fn() => $events?->append('disconnect'));
        $client->onReconnect(static function () use (
            $reconnected,
            $events,
        ): void {
            $events?->append('reconnect');

            if (!$reconnected->isComplete()) {
                $reconnected->complete();
            }
        });

        return $reconnected->getFuture();
    }

    /**
     * @param ?non-empty-string $upstream
     */
    private function proxy(?string $upstream = null): PausableProxy
    {
        return $this->proxies[] = PausableProxy::start($upstream ?? $this->natsAddress());
    }

    /**
     * Brings the server behind a killed proxy back on the same address.
     */
    private function restart(PausableProxy $proxy): PausableProxy
    {
        return $this->proxies[] = PausableProxy::start($this->natsAddress(), $proxy->address);
    }

    /**
     * @param ?non-negative-int $maxReconnects
     * @param non-negative-int $reconnectBufferSize
     */
    private function clientVia(
        PausableProxy $proxy,
        float $reconnectWait = 2.0,
        ?int $maxReconnects = 60,
        int $reconnectBufferSize = 8 * 1024 * 1024,
        bool $verbose = false,
    ): Client {
        $config = $this->config();

        return $this->track(new Client(new Config(
            urls: [$proxy->address],
            verbose: $verbose,
            user: $config->user,
            password: $config->password,
            noResponders: true,
            maxReconnects: $maxReconnects,
            reconnectWait: $reconnectWait,
            reconnectBufferSize: $reconnectBufferSize,
        )));
    }

    private function otherClient(): Client
    {
        return $this->track(new Client($this->config()));
    }

    private function track(Client $client): Client
    {
        return $this->clients[] = $client;
    }

    /**
     * @param \Closure(): bool $condition
     */
    private function waitFor(\Closure $condition): void
    {
        $deadline = microtime(true) + 5;

        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail('The condition was not met in time.');
            }

            delay(0.01);
        }
    }

    /**
     * @return non-empty-string
     */
    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'nats');
        \assert($file !== false);

        file_put_contents($file, $contents);

        return $this->files[] = $file;
    }

    /**
     * @return non-empty-list<non-empty-string> the `host:port` of the servers of the cluster
     */
    private function clusterUrls(): array
    {
        $dsn = getenv('THESIS_NATS_CLUSTER_DSN');
        if (!\is_string($dsn) || $dsn === '') {
            self::markTestSkipped('THESIS_NATS_CLUSTER_DSN must be set.');
        }

        return Config::fromURI($dsn)->urls;
    }
}
