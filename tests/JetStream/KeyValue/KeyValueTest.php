<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Revolt\EventLoop;
use Thesis\Nats\Client;
use Thesis\Nats\Exception\StreamNameAlreadyInUse;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\Exception\WrongLastSequence;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\StoreCompression;
use Thesis\Nats\JetStream\Api\StreamSource;
use Thesis\Nats\JetStream\Api\SubjectTransformConfig;
use Thesis\Nats\NatsTestCase;
use Thesis\Nats\PausableProxy;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\delay;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(Bucket::class)]
#[CoversClass(BucketStatus::class)]
#[CoversClass(EntryState::class)]
#[CoversClass(WatchConfig::class)]
#[CoversClass(Watcher::class)]
#[CoversClass(JetStream::class)]
final class KeyValueTest extends NatsTestCase
{
    private const float TIMEOUT = 5;

    private ?PausableProxy $proxy = null;

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->proxy?->stop();
    }

    public function testInitialValuesAreDoneWhenBucketIsPurgedBeforeDelivery(): void
    {
        $this->proxy = PausableProxy::start($this->natsAddress());
        $js = $this->clientWithUrls([$this->proxy->address])->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));
        $kv->put('x', '1');
        $kv->put('y', '2');

        // The inbox of the API replies is subscribed already, so the next SUB is the one of the watch:
        // the consumer counts two pending values, but the server does not deliver them until the SUB arrives.
        $held = $this->proxy->pauseBefore('SUB ');
        $keys = async($kv->keys(...));
        $held->await(new TimeoutCancellation(self::TIMEOUT));

        $other = new Client($this->config());

        try {
            $other->jetStream()->purgeStream("KV_{$bucket}");
        } finally {
            $other->stop();
        }

        $this->proxy->resume();

        // Nothing is delivered then, the idle heartbeat of the watch tells that there is nothing more.
        self::assertSame([], $keys->await(new TimeoutCancellation(self::TIMEOUT * 2)));

        $js->deleteKeyValue($bucket);
    }

    public function testCancellationLimitsReadsOfWatch(): void
    {
        $this->proxy = PausableProxy::start($this->natsAddress());
        $js = $this->clientWithUrls([$this->proxy->address])->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));
        $kv->put('x', '1');

        // The server does not see the requests of the reads, so only the cancellation can end them.
        $this->proxy->pause();
        $resume = EventLoop::delay(self::TIMEOUT, $this->proxy->resume(...));

        foreach ([
            static fn(): array => $kv->keys(cancellation: new TimeoutCancellation(0.1)),
            static fn(): array => $kv->history('x', cancellation: new TimeoutCancellation(0.1)),
            static fn() => $kv->purgeDeletes(cancellation: new TimeoutCancellation(0.1)),
        ] as $read) {
            $start = hrtime(true);

            try {
                $read();
                self::fail('The server does not see the request, the read must be cancelled.');
            } catch (CancelledException) {
            }

            self::assertLessThan(1, (hrtime(true) - $start) / 1e9);
        }

        EventLoop::cancel($resume);
        $this->proxy->resume();

        self::assertSame(['x'], $kv->keys());

        $js->deleteKeyValue($bucket);
    }

    public function testCreateKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));
        self::assertNull($kv->get('invalid'));
        self::assertNotNull($js->keyValue($bucket));

        $js->deleteKeyValue($bucket);
    }

    public function testDeleteKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));
        self::assertNotNull($js->keyValue($bucket));

        $js->deleteKeyValue($bucket);
        self::assertNull($js->keyValue($bucket));
    }

    public function testPutGetKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $ts = new \DateTimeImmutable();
        self::assertSame(1, $kv->put('x', 'y'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);
        self::assertSame($kv->name, $entry->bucket);
        self::assertSame('x', $entry->key);
        self::assertEquals('y', $entry->value);
        self::assertSame(1, $entry->revision);
        self::assertSame(1, $entry->size);
        self::assertGreaterThanOrEqual($ts->getTimestamp(), $entry->created->getTimestamp());

        $js->deleteKeyValue($bucket);
    }

    public function testKeyValueNames(): void
    {
        $js = $this->client()->jetStream();

        $prefix = generateUniqueId(5);
        $in = [];

        $js->createOrUpdateKeyValue(new BucketConfig($in[] = $prefix . generateUniqueId(10)));
        $js->createOrUpdateKeyValue(new BucketConfig($in[] = $prefix . generateUniqueId(10)));
        $js->createOrUpdateKeyValue(new BucketConfig($in[] = $prefix . generateUniqueId(10)));

        $out = array_values(
            array_filter(
                [...$js->keyValueNames()],
                static fn(string $bucket): bool => str_starts_with($bucket, $prefix),
            ),
        );

        sort($in);
        sort($out);

        self::assertEquals($in, $out);

        foreach ($in as $name) {
            $js->deleteKeyValue($name);
        }
    }

    public function testKeyValueList(): void
    {
        $js = $this->client()->jetStream();

        $prefix = generateUniqueId(5);
        $in = [];

        $js->createOrUpdateKeyValue(new BucketConfig($in[] = $prefix . generateUniqueId(10)));
        $js->createOrUpdateKeyValue(new BucketConfig($in[] = $prefix . generateUniqueId(10)));
        $js->createOrUpdateKeyValue(new BucketConfig($in[] = $prefix . generateUniqueId(10)));

        $out = array_map(
            static fn(BucketInfo $info): string => $info->name,
            array_filter(
                [...$js->keyValueList()],
                static fn(BucketInfo $info): bool => str_starts_with($info->name, $prefix),
            ),
        );

        sort($in);
        sort($out);

        self::assertEquals($in, $out);

        foreach ($in as $name) {
            $js->deleteKeyValue($name);
        }
    }

    public function testDeleteBucketKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        self::assertSame(1, $kv->put('x', 'y'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);

        $kv->delete('x');
        self::assertSame(EntryState::Deleted, $kv->get('x')?->state);

        $js->deleteKeyValue($bucket);
    }

    public function testPurgeBucketKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        self::assertSame(1, $kv->put('x', 'y'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);

        $kv->purge('x');
        self::assertSame(EntryState::Purged, $kv->get('x')?->state);

        $js->deleteKeyValue($bucket);
    }

    public function testUpdateBucketKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        self::assertSame(1, $kv->put('x', 'y'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);
        self::assertSame('y', $entry->value);

        $kv->update('x', $entry->revision, 'z');

        $entry = $kv->get('x');
        self::assertNotNull($entry);
        self::assertSame('z', $entry->value);

        $js->deleteKeyValue($bucket);
    }

    public function testWatchAllKeys(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $changes = [];
        $count = 0;

        $subscription = $kv->watch(static function (Entry $entry, Subscription $subscription) use (
            &$changes,
            &$count,
        ): void {
            $changes["{$entry->key}:{$entry->revision}"] = [$entry->value, $entry->size];

            if (++$count >= 3) {
                $subscription->stop();
            }
        });

        $kv->put('x', 'y');
        $kv->put('a', 'bb');
        $kv->put('x', 'www');

        $subscription->awaitCompletion();

        self::assertSame(
            [
                'x:1' => ['y', 1],
                'a:2' => ['bb', 2],
                'x:3' => ['www', 3],
            ],
            $changes,
        );

        $js->deleteKeyValue($bucket);
    }

    public function testWatchKey(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $changes = [];
        $count = 0;

        $subscription = $kv->watch(
            static function (Entry $entry, Subscription $subscription) use (
                &$changes,
                &$count,
            ): void {
                $changes["{$entry->key}:{$entry->revision}"] = $entry->value;

                if (++$count >= 2) {
                    $subscription->stop();
                }
            },
            keys: 'x',
        );


        $kv->put('x', 'y');
        $kv->put('a', 'b');
        $kv->put('x', 'w');

        $subscription->awaitCompletion();

        self::assertSame(
            [
                'x:1' => 'y',
                'x:3' => 'w',
            ],
            $changes,
        );

        $js->deleteKeyValue($bucket);
    }

    public function testWatchKeyDeleted(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $changes = [];
        $count = 0;

        $subscription = $kv->watch(
            static function (Entry $entry, Subscription $subscription) use (
                &$changes,
                &$count,
            ): void {
                $changes["{$entry->key}:{$entry->revision}"] = $entry->value;

                if (++$count >= 2) {
                    $subscription->stop();
                }
            },
            keys: 'x',
        );

        $kv->put('x', 'y');
        $kv->put('a', 'b');
        $kv->delete('x');

        $subscription->awaitCompletion();

        self::assertSame(
            [
                'x:1' => 'y',
                'x:3' => null,
            ],
            $changes,
        );

        $js->deleteKeyValue($bucket);
    }

    public function testWatchIgnoreDeletes(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $changes = [];

        $subscription = $kv->watch(
            static function (Entry $entry, Subscription $subscription) use (&$changes): void {
                $changes["{$entry->key}:{$entry->revision}"] = $entry->value;
                $subscription->stop();
            },
            keys: 'x',
            config: new WatchConfig(ignoreDeletes: true),
        );

        $kv->put('x', 'y');
        $kv->put('a', 'b');
        $kv->delete('x');

        $subscription->awaitCompletion();

        self::assertSame(
            [
                'x:1' => 'y',
            ],
            $changes,
        );

        $js->deleteKeyValue($bucket);
    }

    public function testWatchHeadersOnly(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $changes = [];
        $count = 0;

        $subscription = $kv->watch(
            static function (Entry $entry, Subscription $subscription) use (
                &$changes,
                &$count,
            ): void {
                $changes["{$entry->key}:{$entry->revision}"] = [$entry->value, $entry->size];

                if (++$count >= 2) {
                    $subscription->stop();
                }
            },
            config: new WatchConfig(headersOnly: true),
        );

        $kv->put('x', 'y');
        $kv->put('a', 'bb');

        $subscription->awaitCompletion();

        self::assertSame(
            [
                'x:1' => [null, 1],
                'a:2' => [null, 2],
            ],
            $changes,
        );

        $js->deleteKeyValue($bucket);
    }

    public function testCreate(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        self::assertSame(1, $kv->create('x', 'y'));

        try {
            $kv->create('x', 'z');
            self::fail('The second create of the key is expected to fail.');
        } catch (WrongLastSequence $e) {
            self::assertSame(10071, $e->errCode);
        }

        self::assertSame('y', $kv->get('x')?->value);

        $js->deleteKeyValue($bucket);
    }

    public function testCreateOverDeletedKey(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $kv->put('x', 'y');
        $kv->delete('x');

        self::assertSame(3, $kv->create('x', 'z'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);
        self::assertSame(EntryState::Created, $entry->state);
        self::assertSame('z', $entry->value);

        $js->deleteKeyValue($bucket);
    }

    public function testCreateOverPurgedKey(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 5));

        $kv->put('x', 'y');
        $kv->put('x', 'w');
        $kv->purge('x');

        self::assertSame(4, $kv->create('x', 'z'));
        self::assertSame('z', $kv->get('x')?->value);

        $js->deleteKeyValue($bucket);
    }

    public function testPutWithTtlAndCreateOverExpiredKey(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig(
            bucket: $bucket = generateUniqueId(10),
            limitMarkerTtl: TimeSpan::fromSeconds(5),
        ));

        $kv->put('x', 'y', ttl: TimeSpan::fromSeconds(1));
        self::assertSame('y', $kv->get('x')?->value);

        // Once the entry expires, the server places a marker with the Nats-Marker-Reason header.
        $deadline = microtime(true) + self::TIMEOUT;

        do {
            delay(0.1);
            $state = $kv->get('x')?->state;
        } while ($state === EntryState::Created && microtime(true) < $deadline);

        self::assertSame(EntryState::Purged, $state);

        $revision = $kv->create('x', 'z', ttl: TimeSpan::fromSeconds(60));

        $entry = $kv->get('x');
        self::assertNotNull($entry);
        self::assertSame($revision, $entry->revision);
        self::assertSame('z', $entry->value);

        $js->deleteKeyValue($bucket);
    }

    public function testHistory(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 10));

        $kv->put('x', '1');
        $kv->put('a', 'b');
        $kv->put('x', '2');
        $kv->delete('x');

        self::assertSame(
            [
                [1, '1', EntryState::Created],
                [3, '2', EntryState::Created],
                [4, null, EntryState::Deleted],
            ],
            array_map(
                static fn(Entry $entry): array => [$entry->revision, $entry->value, $entry->state],
                $kv->history('x'),
            ),
        );

        self::assertSame([], $kv->history('unknown'));

        $js->deleteKeyValue($bucket);
    }

    public function testHistoryOfKeysMatchingPattern(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 10));

        $kv->put('app.env', 'prod');
        $kv->put('database.dsn', 'mysql:');
        $kv->put('app.debug', '1');
        $kv->put('app.env', 'dev');

        self::assertSame(
            [
                [1, 'app.env', 'prod'],
                [3, 'app.debug', '1'],
                [4, 'app.env', 'dev'],
            ],
            array_map(
                static fn(Entry $entry): array => [$entry->revision, $entry->key, $entry->value],
                $kv->history('app.*'),
            ),
        );

        $js->deleteKeyValue($bucket);
    }

    public function testKeys(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 5));

        self::assertSame([], $kv->keys());

        $kv->put('app.env', 'prod');
        $kv->put('app.debug', '0');
        $kv->put('app.env', 'dev');
        $kv->put('db.dsn', 'mysql://');
        $kv->put('db.user', 'root');
        $kv->delete('db.user');
        $kv->put('cache.dsn', 'redis://');
        $kv->purge('cache.dsn');

        $keys = $kv->keys();
        sort($keys);
        self::assertSame(['app.debug', 'app.env', 'db.dsn'], $keys);

        $keys = $kv->keys('app.*');
        sort($keys);
        self::assertSame(['app.debug', 'app.env'], $keys);

        $keys = $kv->keys(['app.env', 'db.>']);
        sort($keys);
        self::assertSame(['app.env', 'db.dsn'], $keys);

        // The watch consumers of the reads are deleted once they are done.
        self::assertSame([], [...$js->consumerNames("KV_{$bucket}")]);

        $js->deleteKeyValue($bucket);
    }

    public function testStatus(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig(
            bucket: $bucket = generateUniqueId(10),
            history: 3,
            ttl: TimeSpan::fromHours(1),
            compression: true,
        ));

        $kv->put('x', 'y');
        $kv->put('x', 'z');

        $status = $kv->status();
        self::assertSame($bucket, $status->bucket);
        self::assertSame(2, $status->values);
        self::assertSame(3, $status->history);
        self::assertSame(3600, $status->ttl?->toSeconds());
        self::assertGreaterThan(0, $status->bytes);
        self::assertSame('JetStream', $status->backingStore);
        self::assertTrue($status->compressed);
        self::assertNull($status->limitMarkerTtl);
        self::assertSame(StoreCompression::S2, $status->info->config->compression);

        $plain = $js->createOrUpdateKeyValue(new BucketConfig($plainBucket = generateUniqueId(10)))->status();
        self::assertSame(0, $plain->values);
        self::assertNull($plain->ttl);
        self::assertFalse($plain->compressed);

        $js->deleteKeyValue($bucket);
        $js->deleteKeyValue($plainBucket);
    }

    public function testPurgeDeletesKeepsRecentMarkers(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 10));

        $kv->put('x', '1');
        $kv->put('x', '2');
        $kv->delete('x');
        $kv->put('y', '1');
        $kv->purge('y');
        $kv->put('z', '1');

        $kv->purgeDeletes();

        self::assertSame(
            [EntryState::Deleted],
            array_map(static fn(Entry $entry): EntryState => $entry->state, $kv->history('x')),
        );
        self::assertSame(
            [EntryState::Purged],
            array_map(static fn(Entry $entry): EntryState => $entry->state, $kv->history('y')),
        );
        self::assertSame('1', $kv->get('z')?->value);
        self::assertSame(3, $kv->status()->values);

        $js->deleteKeyValue($bucket);
    }

    public function testPurgeDeletesRemovesOldMarkers(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 10));

        $kv->put('x', '1');
        $kv->delete('x');
        $kv->put('y', '1');
        $kv->purge('y');
        $kv->put('z', '1');

        $kv->purgeDeletes(TimeSpan::fromSeconds(0));

        self::assertSame([], $kv->history('x'));
        self::assertSame([], $kv->history('y'));
        self::assertNull($kv->get('x'));
        self::assertSame(['z'], $kv->keys());
        self::assertSame(1, $kv->status()->values);

        $js->deleteKeyValue($bucket);
    }

    public function testCreateKeyValueOnly(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createKeyValue(new BucketConfig($bucket = generateUniqueId(10)));
        $kv->put('x', 'y');

        // The same config is not a conflict.
        self::assertSame('y', $js->createKeyValue(new BucketConfig($bucket))->get('x')?->value);

        try {
            $js->createKeyValue(new BucketConfig($bucket, history: 5));
            self::fail('Creating an existing bucket with another config is expected to fail.');
        } catch (StreamNameAlreadyInUse) {
        }

        self::assertSame(1, $kv->status()->history);

        $js->deleteKeyValue($bucket);
    }

    public function testUpdateKeyValueOnly(): void
    {
        $js = $this->client()->jetStream();

        $bucket = generateUniqueId(10);

        try {
            $js->updateKeyValue(new BucketConfig($bucket));
            self::fail('Updating a missing bucket is expected to fail.');
        } catch (StreamNotFound) {
        }

        self::assertNull($js->keyValue($bucket));

        $js->createKeyValue(new BucketConfig($bucket));
        $kv = $js->updateKeyValue(new BucketConfig($bucket, history: 5));
        self::assertSame(5, $kv->status()->history);

        $js->deleteKeyValue($bucket);
    }

    public function testMirror(): void
    {
        $js = $this->client()->jetStream();

        $origin = $js->createOrUpdateKeyValue(new BucketConfig($originBucket = generateUniqueId(10)));
        $origin->put('name', 'derek');
        $origin->put('age', '22');

        $mirror = $js->createOrUpdateKeyValue(new BucketConfig(
            bucket: $mirrorBucket = generateUniqueId(10),
            mirror: new StreamSource($originBucket),
        ));

        $config = $mirror->status()->info->config;
        self::assertSame("KV_{$originBucket}", $config->mirror?->name);
        self::assertTrue($config->mirrorDirect);
        self::assertSame([], $config->subjects ?? []);

        self::assertSame('derek', self::awaitValue($mirror, 'name')?->value);
        self::assertSame('22', self::awaitValue($mirror, 'age')?->value);

        // A put to the mirror goes to the origin bucket and comes back to the mirror.
        $mirror->put('name', 'rip');
        self::assertSame('rip', $origin->get('name')?->value);
        self::assertSame('rip', self::awaitValue($mirror, 'name', 'rip')?->value);

        // The bucket bound by name is a mirror as well.
        $bound = $js->keyValue($mirrorBucket);
        self::assertNotNull($bound);
        self::assertSame('rip', $bound->get('name')?->value);

        $keys = $mirror->keys();
        sort($keys);
        self::assertSame(['age', 'name'], $keys);

        $js->deleteKeyValue($mirrorBucket);
        $js->deleteKeyValue($originBucket);
    }

    public function testSources(): void
    {
        $js = $this->client()->jetStream();

        $first = $js->createOrUpdateKeyValue(new BucketConfig($firstBucket = generateUniqueId(10)));
        $second = $js->createOrUpdateKeyValue(new BucketConfig($secondBucket = generateUniqueId(10)));

        $sourced = $js->createOrUpdateKeyValue(new BucketConfig(
            bucket: $sourcedBucket = generateUniqueId(10),
            sources: [
                new StreamSource($firstBucket),
                new StreamSource("KV_{$secondBucket}"),
            ],
        ));

        $first->put('key1', 'value1');
        $second->put('key2', 'value2');

        self::assertSame('value1', self::awaitValue($sourced, 'key1')?->value);
        self::assertSame('value2', self::awaitValue($sourced, 'key2')?->value);

        // Own entries live next to the sourced ones.
        $sourced->put('key3', 'value3');
        self::assertSame('value3', $sourced->get('key3')?->value);

        $config = $sourced->status()->info->config;
        self::assertSame(["\$KV.{$sourcedBucket}.>"], $config->subjects);
        self::assertEquals(
            [
                [new SubjectTransformConfig("\$KV.{$firstBucket}.>", "\$KV.{$sourcedBucket}.>")],
                [new SubjectTransformConfig("\$KV.{$secondBucket}.>", "\$KV.{$sourcedBucket}.>")],
            ],
            array_map(static fn(StreamSource $source): ?array => $source->subjectTransforms, $config->sources ?? []),
        );
        self::assertSame(
            ["KV_{$firstBucket}", "KV_{$secondBucket}"],
            array_map(static fn(StreamSource $source): string => $source->name, $config->sources ?? []),
        );

        $js->deleteKeyValue($sourcedBucket);
        $js->deleteKeyValue($firstBucket);
        $js->deleteKeyValue($secondBucket);
    }

    public function testWatchDeliversLatestValuesFirst(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 5));

        $kv->put('x', '1');
        $kv->put('y', '2');
        $kv->put('x', '3');
        $kv->put('z', '4');
        $kv->delete('z');

        /** @var \ArrayObject<int, array{string, int, ?string, EntryState}> $entries */
        $entries = new \ArrayObject();

        $watcher = $kv->watch(static function (Entry $entry) use ($entries): void {
            $entries[] = [$entry->key, $entry->revision, $entry->value, $entry->state];
        });

        $watcher->awaitInitialValues(new TimeoutCancellation(self::TIMEOUT));
        self::assertTrue($watcher->initialValuesDelivered());

        self::assertSame(
            [
                ['y', 2, '2', EntryState::Created],
                ['x', 3, '3', EntryState::Created],
                ['z', 5, null, EntryState::Deleted],
            ],
            $entries->getArrayCopy(),
        );

        $kv->put('y', '6');
        self::awaitCount($entries, 4);
        self::assertSame(['y', 6, '6', EntryState::Created], $entries[3]);

        $watcher->stop();
        self::assertTrue($watcher->completed());
        self::assertSame([], [...$js->consumerNames("KV_{$bucket}")]);

        $js->deleteKeyValue($bucket);
    }

    public function testWatchEmptyBucketHasNoInitialValues(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        /** @var \ArrayObject<int, string> $keys */
        $keys = new \ArrayObject();

        $watcher = $kv->watch(static function (Entry $entry) use ($keys): void {
            $keys[] = $entry->key;
        });

        self::assertTrue($watcher->initialValuesDelivered());
        $watcher->awaitInitialValues(new TimeoutCancellation(self::TIMEOUT));

        $kv->put('x', 'y');
        self::awaitCount($keys, 1);
        self::assertSame(['x'], $keys->getArrayCopy());

        $watcher->stop();

        $js->deleteKeyValue($bucket);
    }

    public function testWatchIncludeHistory(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 5));

        $kv->put('x', '1');
        $kv->put('y', '2');
        $kv->put('x', '3');

        /** @var \ArrayObject<int, string> $entries */
        $entries = new \ArrayObject();

        $watcher = $kv->watch(
            static function (Entry $entry) use ($entries): void {
                $entries[] = "{$entry->key}:{$entry->revision}";
            },
            keys: 'x',
            config: new WatchConfig(includeHistory: true),
        );

        $watcher->awaitInitialValues(new TimeoutCancellation(self::TIMEOUT));
        self::assertSame(['x:1', 'x:3'], $entries->getArrayCopy());

        $watcher->stop();

        $js->deleteKeyValue($bucket);
    }

    public function testWatchUpdatesOnly(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $kv->put('x', '1');

        /** @var \ArrayObject<int, string> $entries */
        $entries = new \ArrayObject();

        $watcher = $kv->watch(
            static function (Entry $entry) use ($entries): void {
                $entries[] = "{$entry->key}:{$entry->value}";
            },
            config: new WatchConfig(updatesOnly: true),
        );

        self::assertTrue($watcher->initialValuesDelivered());

        $kv->put('y', '2');
        self::awaitCount($entries, 1);
        self::assertSame(['y:2'], $entries->getArrayCopy());

        $watcher->stop();

        $js->deleteKeyValue($bucket);
    }

    public function testWatchMetaOnly(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $kv->put('x', 'yyy');

        /** @var \ArrayObject<int, array{string, ?string, int}> $entries */
        $entries = new \ArrayObject();

        $watcher = $kv->watch(
            static function (Entry $entry) use ($entries): void {
                $entries[] = [$entry->key, $entry->value, $entry->size];
            },
            config: new WatchConfig(metaOnly: true),
        );

        $watcher->awaitInitialValues(new TimeoutCancellation(self::TIMEOUT));
        self::assertSame([['x', null, 3]], $entries->getArrayCopy());

        $watcher->stop();

        $js->deleteKeyValue($bucket);
    }

    public function testWatchResumeFromRevision(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10), history: 5));

        $kv->put('x', '1');
        $kv->put('y', '2');
        $kv->put('x', '3');
        $kv->put('z', '4');

        /** @var \ArrayObject<int, string> $entries */
        $entries = new \ArrayObject();

        $watcher = $kv->watch(
            static function (Entry $entry) use ($entries): void {
                $entries[] = "{$entry->key}:{$entry->revision}";
            },
            config: new WatchConfig(resumeFromRevision: 2),
        );

        $watcher->awaitInitialValues(new TimeoutCancellation(self::TIMEOUT));
        self::assertSame(['y:2', 'x:3', 'z:4'], $entries->getArrayCopy());

        $watcher->stop();

        $js->deleteKeyValue($bucket);
    }

    public function testWatchIgnoreDeletesCountsSkippedInitialValues(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $kv->put('x', '1');
        $kv->put('y', '2');
        $kv->delete('y');

        /** @var \ArrayObject<int, string> $entries */
        $entries = new \ArrayObject();

        $watcher = $kv->watch(
            static function (Entry $entry) use ($entries): void {
                $entries[] = $entry->key;
            },
            config: new WatchConfig(ignoreDeletes: true),
        );

        $watcher->awaitInitialValues(new TimeoutCancellation(self::TIMEOUT));
        self::assertSame(['x'], $entries->getArrayCopy());

        $watcher->stop();

        $js->deleteKeyValue($bucket);
    }

    public function testAwaitInitialValuesRethrowsHandlerError(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));

        $kv->put('x', '1');
        $kv->put('y', '2');

        $watcher = $kv->watch(static function (Entry $entry): void {
            throw new \RuntimeException("Cannot handle {$entry->key}.");
        });

        try {
            $watcher->awaitInitialValues(new TimeoutCancellation(self::TIMEOUT));
            self::fail('The error of the handler is expected.');
        } catch (\RuntimeException $e) {
            self::assertSame('Cannot handle x.', $e->getMessage());
        }

        self::assertTrue($watcher->completed());
        $watcher->stop();

        $js->deleteKeyValue($bucket);
    }

    public function testWatchConfigRejectsConflictingOptions(): void
    {
        foreach ([
            static fn(): WatchConfig => new WatchConfig(includeHistory: true, updatesOnly: true),
            static fn(): WatchConfig => new WatchConfig(updatesOnly: true, resumeFromRevision: 1),
            /** @phpstan-ignore argument.type */
            static fn(): WatchConfig => new WatchConfig(resumeFromRevision: 0),
        ] as $config) {
            try {
                $config();
                self::fail('The config is expected to be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertTrue(new WatchConfig(includeHistory: true, resumeFromRevision: 1)->includeHistory);
    }

    /**
     * @param non-empty-string $key
     */
    private static function awaitValue(Bucket $kv, string $key, ?string $value = null): ?Entry
    {
        $deadline = microtime(true) + self::TIMEOUT;

        do {
            $entry = $kv->get($key);

            if ($entry !== null && ($value === null || $entry->value === $value)) {
                return $entry;
            }

            delay(0.05);
        } while (microtime(true) < $deadline);

        return $entry;
    }

    /**
     * @template T
     * @param \ArrayObject<int, T> $entries
     */
    private static function awaitCount(\ArrayObject $entries, int $count): void
    {
        $deadline = microtime(true) + self::TIMEOUT;

        while (\count($entries) < $count && microtime(true) < $deadline) {
            delay(0.01);
        }
    }
}
