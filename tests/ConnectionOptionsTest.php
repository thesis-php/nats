<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use Thesis\Nats\Exception\DrainTimedOut;
use Thesis\Nats\Exception\FeatureIsNotSupported;
use Thesis\Nats\Internal\Id;
use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

#[CoversClass(Client::class)]
#[CoversClass(Config::class)]
#[CoversClass(ServerInfo::class)]
#[CoversClass(ConnectionStatus::class)]
final class ConnectionOptionsTest extends NatsTestCase
{
    /** @var list<Client> */
    private array $clients = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stop();
        }

        parent::tearDown();
    }

    public function testNoEchoKeepsOwnMessagesFromOwnSubscriptions(): void
    {
        $quiet = $this->track(new Client($this->configWith(noEcho: true)));
        $other = $this->track(new Client($this->configWith()));
        $subject = Id\generateUniqueId();

        $own = $quiet->subscribeIterator($subject);
        $all = $other->subscribeIterator($subject);
        $quiet->ping();
        $other->ping();

        $quiet->publish($subject, new Message('from quiet'));
        $other->publish($subject, new Message('from other'));

        self::assertSame(['from quiet', 'from other'], self::take($all, 2));
        // The own message would have come first: the subscription gets the other one only.
        self::assertSame(['from other'], self::take($own, 1));
    }

    public function testNoEchoIsRefusedByServerWithoutTheEchoOption(): void
    {
        // A server before 1.2.0 announces the original protocol, which has no echo option.
        $server = listen('127.0.0.1:0');
        $address = $server->getAddress()->toString();
        self::assertNotSame('', $address);

        $future = async(static function () use ($server): void {
            $socket = $server->accept();
            $socket?->write("INFO {\"version\":\"1.1.0\",\"proto\":0}\r\n");
            $socket?->read();
        });
        $future->ignore();

        $client = $this->track(new Client(new Config(urls: [$address], allowReconnect: false, noEcho: true)));

        try {
            $client->connect();
            self::fail('The server without the echo option is accepted.');
        } catch (FeatureIsNotSupported $e) {
            self::assertStringContainsString('1.1.0', $e->getMessage());
        } finally {
            $server->close();
        }
    }

    public function testInboxPrefix(): void
    {
        $client = $this->track(new Client($this->configWith(inboxPrefix: 'custom.inbox')));
        $responder = $this->track(new Client($this->configWith()));
        $subject = Id\generateUniqueId();

        /** @var \ArrayObject<int, string> $replySubjects */
        $replySubjects = new \ArrayObject();
        $responder->subscribe($subject, static function (Delivery $delivery) use ($replySubjects): void {
            $replySubjects[] = (string) $delivery->replyTo;
            $delivery->reply(new Message('pong'));
        });
        $responder->ping();

        self::assertSame('pong', $client->request($subject, new Message('ping'), timeout: 5)->message->payload);
        self::assertCount(1, $replySubjects);
        self::assertStringStartsWith('custom.inbox.', $replySubjects[0] ?? '');

        $inbox = $client->newInbox();
        self::assertStringStartsWith('custom.inbox.', $inbox);
        self::assertNotSame($inbox, $client->newInbox());
    }

    public function testJetStreamUsesTheInboxPrefix(): void
    {
        $client = $this->track(new Client($this->configWith(inboxPrefix: 'custom.inbox')));
        $js = $client->jetStream();
        $bucketName = Id\generateUniqueId(10);
        $bucket = $js->createOrUpdateKeyValue(new JetStream\KeyValue\BucketConfig($bucketName));

        try {
            /** @var DeferredFuture<JetStream\KeyValue\Entry> $watched */
            $watched = new DeferredFuture();

            // The watch consumer delivers to an inbox of the prefix.
            $watcher = $bucket->watch(static function (JetStream\KeyValue\Entry $entry) use ($watched): void {
                if (!$watched->isComplete()) {
                    $watched->complete($entry);
                }
            });
            $bucket->put('key', 'value');

            self::assertSame('value', $watched->getFuture()->await(new TimeoutCancellation(5))->value);
            $watcher->stop();
        } finally {
            $js->deleteKeyValue($bucketName);
        }
    }

    public function testServerInfo(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->clientNamed($name);

        // It does not connect.
        self::assertNull($client->serverInfo());

        $client->connect();
        $info = $client->serverInfo();

        self::assertNotNull($info);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $info->version);
        self::assertNotNull($info->serverId);
        self::assertTrue($info->headers);
        self::assertTrue($info->jetStream);
        self::assertSame(1, $info->proto);
        self::assertNotNull($info->maxPayload);
        self::assertSame($this->connectionId($name), $info->clientId);
        self::assertFalse($info->lameDuckMode);

        $client->stop();
        self::assertNull($client->serverInfo());
    }

    public function testServerInfoFollowsTheUpdates(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->clientNamed($name);

        /** @var DeferredFuture<void> $lameDuck */
        $lameDuck = new DeferredFuture();
        $client->onLameDuckMode(static fn() => $lameDuck->complete());
        $client->connect();

        $this->lameDuck($name);
        $lameDuck->getFuture()->await(new TimeoutCancellation(5));

        self::assertTrue($client->serverInfo()?->lameDuckMode);
    }

    public function testStatus(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->clientNamed($name);

        self::assertSame(ConnectionStatus::Disconnected, $client->status());

        $client->connect();
        self::assertSame(ConnectionStatus::Connected, $client->status());

        /** @var DeferredFuture<ConnectionStatus> $disconnected */
        $disconnected = new DeferredFuture();
        $client->onDisconnect(static fn() => $disconnected->complete($client->status()));
        /** @var DeferredFuture<ConnectionStatus> $reconnected */
        $reconnected = new DeferredFuture();
        $client->onReconnect(static fn() => $reconnected->complete($client->status()));

        $this->kick($name);

        self::assertSame(ConnectionStatus::Reconnecting, $disconnected->getFuture()->await(new TimeoutCancellation(5)));
        self::assertSame(ConnectionStatus::Connected, $reconnected->getFuture()->await(new TimeoutCancellation(5)));

        $client->stop();
        self::assertSame(ConnectionStatus::Disconnected, $client->status());
    }

    public function testStatusWhileDraining(): void
    {
        $client = $this->track(new Client($this->configWith()));
        $subject = Id\generateUniqueId();

        /** @var DeferredFuture<void> $handling */
        $handling = new DeferredFuture();
        $client->subscribe($subject, static function () use ($handling): void {
            $handling->complete();
            delay(0.3);
        });
        $client->publish($subject, new Message('slow'));
        $handling->getFuture()->await(new TimeoutCancellation(5));

        $drain = async($client->drain(...));

        delay(0.1);
        self::assertSame(ConnectionStatus::Draining, $client->status());

        $drain->await(new TimeoutCancellation(5));
        self::assertSame(ConnectionStatus::Disconnected, $client->status());
    }

    public function testDrainTimeoutClosesTheConnectionAnyway(): void
    {
        $client = $this->track(new Client($this->configWith(drainTimeout: 0.3)));
        $subject = Id\generateUniqueId();

        /** @var DeferredFuture<void> $handling */
        $handling = new DeferredFuture();
        // Longer than the drain timeout, short enough not to outlive the test by much: a handler left running
        // in the event loop would otherwise act in the tests that follow.
        $client->subscribe($subject, static function () use ($handling): void {
            $handling->complete();
            delay(1);
        });
        $client->publish($subject, new Message('stuck'));
        $handling->getFuture()->await(new TimeoutCancellation(5));

        $start = microtime(true);

        try {
            $client->drain();
            self::fail('The drain stuck in a handler did not time out.');
        } catch (DrainTimedOut) {
        }

        self::assertLessThan(0.9, microtime(true) - $start);
        self::assertSame(ConnectionStatus::Disconnected, $client->status());
        self::assertNull($client->serverInfo());
    }

    public function testLogsConnectionEvents(): void
    {
        $name = Id\generateUniqueId();
        $logger = new CollectingLogger();
        $config = $this->config();
        $client = $this->track(new Client(
            new Config(urls: $config->urls, user: $config->user, password: $config->password, clientName: $name, reconnectWait: 0.1),
            logger: $logger,
        ));

        /** @var DeferredFuture<void> $reconnected */
        $reconnected = new DeferredFuture();
        $client->onReconnect(static fn() => $reconnected->complete());
        $client->connect();

        $this->kick($name);
        $reconnected->getFuture()->await(new TimeoutCancellation(5));

        $client->stop();
        delay(0.01);

        self::assertSame(
            [
                ['info', 'Connected to the nats server.'],
                ['warning', 'The connection to the nats server is lost, reconnecting.'],
                ['info', 'Reconnected to the nats server.'],
                ['info', 'The connection to the nats server is closed.'],
            ],
            array_map(static fn(array $record): array => [$record[0], $record[1]], $logger->records->getArrayCopy()),
        );
        self::assertArrayHasKey('server_id', $logger->records[0][2] ?? []);
    }

    public function testLogsServerErrors(): void
    {
        $logger = new CollectingLogger();
        $config = $this->config();
        $client = $this->track(new Client(
            new Config(urls: $config->urls, user: 'restricted', password: 'Pswd1'),
            logger: $logger,
        ));

        /** @var DeferredFuture<\Throwable> $error */
        $error = new DeferredFuture();
        $client->onError(static fn(\Throwable $e) => $error->complete($e));

        // A publish outside of the permissions of the user is refused asynchronously.
        $client->publish('forbidden.subject', new Message('x'));
        $error->getFuture()->await(new TimeoutCancellation(5));

        $warnings = array_values(array_filter(
            $logger->records->getArrayCopy(),
            static fn(array $record): bool => $record[0] === 'warning',
        ));

        self::assertCount(1, $warnings);
        $message = $warnings[0][2]['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('Permissions Violation', $message);
    }

    /**
     * @param ?non-empty-string $inboxPrefix
     */
    private function configWith(bool $noEcho = false, ?string $inboxPrefix = null, float $drainTimeout = 30.0): Config
    {
        $config = $this->config();

        return new Config(
            urls: $config->urls,
            user: $config->user,
            password: $config->password,
            noEcho: $noEcho,
            inboxPrefix: $inboxPrefix ?? '_INBOX',
            drainTimeout: $drainTimeout,
        );
    }

    private function track(Client $client): Client
    {
        $this->clients[] = $client;

        return $client;
    }

    /**
     * @param Iterator<Delivery> $deliveries
     * @param positive-int $count
     * @return list<string>
     */
    private static function take(Iterator $deliveries, int $count): array
    {
        $payloads = [];

        foreach ($deliveries as $delivery) {
            $payloads[] = (string) $delivery->message->payload;

            if (\count($payloads) === $count) {
                break;
            }
        }

        return $payloads;
    }
}

/**
 * @internal
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var \ArrayObject<int, array{string, string, array<array-key, mixed>}> */
    public readonly \ArrayObject $records;

    public function __construct()
    {
        /** @var \ArrayObject<int, array{string, string, array<array-key, mixed>}> $records */
        $records = new \ArrayObject();
        $this->records = $records;
    }

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [\is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}
