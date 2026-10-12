<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Socket\ServerSocket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Thesis\Nats\Exception\InvalidBucketName;
use Thesis\Nats\Exception\InvalidKey;
use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;
use Thesis\Nats\Exception\ObjectIsInvalid;
use Thesis\Nats\Internal\Validation\Validate;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\Counter\CounterConfig;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use Thesis\Nats\JetStream\ObjectStore\ObjectMeta;
use Thesis\Nats\JetStream\ObjectStore\StoreConfig;
use Thesis\Nats\JetStream\PullConsumeConfig;
use Thesis\Nats\Micro\EndpointConfig;
use Thesis\Nats\Micro\GroupConfig;
use Thesis\Nats\Micro\Request;
use Thesis\Nats\Micro\Response;
use Thesis\Nats\Micro\ServiceConfig;
use function Amp\async;
use function Amp\Socket\listen;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(Validate::class)]
final class ValidationTest extends NatsTestCase
{
    /**
     * Previously these calls went to the server, here a closed port, and failed with a connection error,
     * or, with a server, with "-ERR 'Invalid Subject'", a JetStream API error, or did not fail at all.
     *
     * @param \Closure(Client, JetStream): mixed $call
     * @param class-string<NatsException> $exception
     */
    #[DataProvider('provideInvalidCallFailsBeforeConnectingCases')]
    public function testInvalidCallFailsBeforeConnecting(\Closure $call, string $exception): void
    {
        $server = listen('127.0.0.1:0');
        $connections = 0;

        async(static function () use ($server, &$connections): void {
            while (($socket = $server->accept()) !== null) {
                ++$connections;
                $socket->close();
            }
        })->ignore();

        try {
            $nc = $this->clientWithUrls([self::address($server)]);

            try {
                $call($nc, new JetStream($nc));
                self::fail("{$exception} must be thrown.");
            } catch (NatsException $e) {
                self::assertInstanceOf($exception, $e, $e->getMessage());
            }

            self::assertSame(0, $connections, 'Nothing must be sent to the server.');
        } finally {
            $server->close();
        }
    }

    /**
     * @return iterable<string, array{\Closure(Client, JetStream): mixed, class-string<NatsException>}>
     */
    public static function provideInvalidCallFailsBeforeConnectingCases(): iterable
    {
        $noop = static function (): void {};

        yield 'publish with a wildcard' => [static fn(Client $nc) => $nc->publish('orders.*'), InvalidSubject::class];
        yield 'publish with whitespace' => [static fn(Client $nc) => $nc->publish('orders new'), InvalidSubject::class];
        yield 'publish with an invalid reply-to' => [static fn(Client $nc) => $nc->publish('orders', replyTo: 'inbox.>'), InvalidSubject::class];
        yield 'request with an empty token' => [static fn(Client $nc) => $nc->request('orders..new'), InvalidSubject::class];
        yield 'subscribe with > not last' => [static fn(Client $nc) => $nc->subscribe('orders.>.new', $noop), InvalidSubject::class];
        yield 'subscribe with an invalid queue group' => [static fn(Client $nc) => $nc->subscribe('orders', $noop, queueGroup: 'my workers'), InvalidSubject::class];
        yield 'subscribe iterator' => [static fn(Client $nc) => $nc->subscribeIterator('.orders'), InvalidSubject::class];
        yield 'jetstream publish' => [static fn(Client $nc, JetStream $js) => $js->publish('orders.*', new Message()), InvalidSubject::class];
        yield 'create stream' => [static fn(Client $nc, JetStream $js) => $js->createStream(new StreamConfig('orders.v1')), InvalidName::class];
        yield 'create stream with an invalid subject' => [
            static fn(Client $nc, JetStream $js) => $js->createStream(new StreamConfig('ORDERS', subjects: ['orders..*'])),
            InvalidSubject::class,
        ];
        yield 'update stream' => [static fn(Client $nc, JetStream $js) => $js->updateStream(new StreamConfig('my orders')), InvalidName::class];
        yield 'stream' => [static fn(Client $nc, JetStream $js) => $js->stream('orders/v1'), InvalidName::class];
        yield 'delete stream' => [static fn(Client $nc, JetStream $js) => $js->deleteStream('orders*'), InvalidName::class];
        yield 'purge stream with an invalid subject' => [
            static fn(Client $nc, JetStream $js) => $js->purgeStream('ORDERS', subject: '>.orders'),
            InvalidSubject::class,
        ];
        yield 'stream names' => [static fn(Client $nc, JetStream $js) => iterator_to_array($js->streamNames('orders..*')), InvalidSubject::class];
        yield 'create consumer' => [
            static fn(Client $nc, JetStream $js) => $js->createConsumer('ORDERS', new ConsumerConfig(durableName: 'my.consumer')),
            InvalidName::class,
        ];
        yield 'create consumer with an invalid filter' => [
            static fn(Client $nc, JetStream $js) => $js->createConsumer('ORDERS', new ConsumerConfig(filterSubject: 'orders.')),
            InvalidSubject::class,
        ];
        yield 'create consumer on an invalid stream' => [
            static fn(Client $nc, JetStream $js) => $js->createOrUpdateConsumer('ORDERS.v1'),
            InvalidName::class,
        ];
        yield 'consumer' => [static fn(Client $nc, JetStream $js) => $js->consumer('ORDERS', 'processor>'), InvalidName::class];
        yield 'delete consumer' => [static fn(Client $nc, JetStream $js) => $js->deleteConsumer('ORDERS', 'my processor'), InvalidName::class];
        yield 'unpin consumer with an invalid group' => [
            static fn(Client $nc, JetStream $js) => $js->unpinConsumer('ORDERS', 'processor', 'jobs.high'),
            InvalidName::class,
        ];
        yield 'create consumer with an invalid priority group' => [
            static fn(Client $nc, JetStream $js) => $js->createConsumer('ORDERS', new ConsumerConfig(priorityGroups: ['seventeen-chars-x'])),
            InvalidName::class,
        ];
        yield 'pull consume config with an invalid group' => [
            static fn() => new PullConsumeConfig(group: 'my jobs'),
            InvalidName::class,
        ];
        yield 'consumer list' => [static fn(Client $nc, JetStream $js) => iterator_to_array($js->consumerList('ORDERS.*')), InvalidName::class];
        yield 'key value' => [static fn(Client $nc, JetStream $js) => $js->keyValue('my.bucket'), InvalidBucketName::class];
        yield 'create key value' => [
            static fn(Client $nc, JetStream $js) => $js->createOrUpdateKeyValue(new BucketConfig('my bucket')),
            InvalidBucketName::class,
        ];
        yield 'delete key value' => [static fn(Client $nc, JetStream $js) => $js->deleteKeyValue('bucket*'), InvalidBucketName::class];
        yield 'object store' => [static fn(Client $nc, JetStream $js) => $js->objectStore('my/store'), InvalidBucketName::class];
        yield 'create object store' => [
            static fn(Client $nc, JetStream $js) => $js->createOrUpdateObjectStore(new StoreConfig('my.store')),
            InvalidBucketName::class,
        ];
        yield 'delete object store' => [static fn(Client $nc, JetStream $js) => $js->deleteObjectStore('my store'), InvalidBucketName::class];
        yield 'create counter' => [
            static fn(Client $nc, JetStream $js) => $js->createOrUpdateCounter(new CounterConfig('my.counter')),
            InvalidName::class,
        ];
        yield 'delete counter' => [static fn(Client $nc, JetStream $js) => $js->deleteCounter('my counter'), InvalidName::class];
    }

    public function testValidSubjectsWithWildcardsStillWork(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $stream = $js->createStream(new StreamConfig(
            name: $name = generateUniqueId(10),
            subjects: ["{$name}.*.>"],
        ));

        try {
            $js->publish("{$name}.orders.new", new Message('x'));

            // The filter with a wildcard is part of the JetStream API request subject, which must not be rejected.
            $consumer = $stream->createConsumer(new ConsumerConfig(
                durableName: 'processor',
                filterSubject: "{$name}.*.new",
            ));
            self::assertSame("{$name}.*.new", $consumer->info->config->filterSubject);

            self::assertNotNull($stream->getLastMessageForSubject("{$name}.orders.new"));
        } finally {
            $js->deleteStream($name);
        }
    }

    public function testInvalidKeyValueKey(): void
    {
        $js = $this->client()->jetStream();
        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        try {
            foreach ([
                static fn() => $kv->put('my key', 'x'),
                static fn() => $kv->put('.key', 'x'),
                static fn() => $kv->put('a.*', 'x'),
                static fn() => $kv->update('a..b', 1, 'x'),
                static fn() => $kv->get('key.'),
                static fn() => $kv->delete('key>'),
                static fn() => $kv->purge('a b'),
                static fn() => $kv->watch(static function (): void {}, ['a.>.b']),
            ] as $i => $call) {
                try {
                    $call();
                    self::fail("Call #{$i} must fail.");
                } catch (InvalidKey) {
                }
            }

            self::assertSame(0, $js->streamInfo("KV_{$bucket}")->state->messages);

            // Wildcards are allowed in watch filters.
            $kv->watch(static function (): void {}, ['a.*', 'b.>'])->stop();
        } finally {
            $js->deleteKeyValue($bucket);
        }
    }

    public function testInvalidCounterSubject(): void
    {
        $js = $this->client()->jetStream();
        $counter = $js->createOrUpdateCounter(new CounterConfig($name = generateUniqueId(10)));

        try {
            foreach ([
                static fn() => $counter->add('clicks.*', 1),
                static fn() => $counter->get('clicks.>'),
                static fn() => iterator_to_array($counter->getMultiple(['clicks..x'])),
            ] as $i => $call) {
                try {
                    $call();
                    self::fail("Call #{$i} must fail.");
                } catch (InvalidSubject) {
                }
            }

            self::assertSame(0, $js->streamInfo("CNT_{$name}")->state->messages);
        } finally {
            $js->deleteCounter($name);
        }
    }

    public function testEmptyObjectName(): void
    {
        $js = $this->client()->jetStream();
        $store = $js->createOrUpdateObjectStore(new StoreConfig($bucket = generateUniqueId(10)));

        try {
            $name = '';

            try {
                /** @phpstan-ignore argument.type */
                $store->put(new ObjectMeta($name), 'x');
                self::fail('Empty object name must be rejected.');
            } catch (ObjectIsInvalid) {
            }

            try {
                /** @phpstan-ignore argument.type */
                $store->info($name);
                self::fail('Empty object name must be rejected.');
            } catch (ObjectIsInvalid) {
            }

            self::assertSame(0, $js->streamInfo("OBJ_{$bucket}")->state->messages);
        } finally {
            $js->deleteObjectStore($bucket);
        }
    }

    public function testInvalidMicroConfigs(): void
    {
        foreach ([
            static fn() => new EndpointConfig('api.echo'),
            static fn() => new EndpointConfig('echo', subject: 'echo.>.x'),
            static fn() => new EndpointConfig('echo', queueGroup: 'my workers'),
            static fn() => new GroupConfig('api.>'),
            static fn() => new GroupConfig('api', queueGroup: 'my workers'),
            static fn() => new ServiceConfig('Echo', '1.0.0', queueGroup: 'my workers'),
        ] as $i => $create) {
            try {
                $create();
                self::fail("Config #{$i} must be rejected.");
            } catch (InvalidName|InvalidSubject) {
            }
        }

        $this->expectNotToPerformAssertions();
    }

    public function testGroupEndpointSubject(): void
    {
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig('EchoService', '1.0.0'));
        $srv
            ->addGroup(new GroupConfig('api'))
            ->addEndpoint(new EndpointConfig('echo', subject: 'echo.*'), static function (Request $request): void {
                $request->respond(new Response($request->subject));
            });

        // The group prefixes the endpoint's own subject, and the endpoint keeps its name, as in nats.go.
        self::assertSame('api.echo.x', $nc->request('api.echo.x')->message->payload);
        $endpoint = $srv->info()->endpoints[0] ?? null;
        self::assertNotNull($endpoint);
        self::assertSame('echo', $endpoint->name);
        self::assertSame('api.echo.*', $endpoint->subject);

        $srv->stop();
    }

    /**
     * @return non-empty-string
     */
    private static function address(ServerSocket $server): string
    {
        $address = $server->getAddress()->toString();
        \assert($address !== '');

        return $address;
    }
}
