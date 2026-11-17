<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\ByteStream\ReadableIterableStream;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Client;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\FileIsNotAccessible;
use Thesis\Nats\Exception\ObjectAlreadyExists;
use Thesis\Nats\Exception\ObjectIsInvalid;
use Thesis\Nats\Exception\ObjectListIsIncomplete;
use Thesis\Nats\Exception\ObjectNotFound;
use Thesis\Nats\Header\Timestamp;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\StorageType;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\NatsTestCase;
use Thesis\Nats\PausableProxy;
use Thesis\Nats\Serialization\Serializer;
use Thesis\Nats\Serialization\ValinorSerializer;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\delay;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(Store::class)]
final class ObjectStoreTest extends NatsTestCase
{
    private const float TIMEOUT = 5;

    private ?PausableProxy $proxy = null;

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->proxy?->stop();
    }

    public function testObjectStoreNames(): void
    {
        $js = $this->client()->jetStream();

        $prefix = generateUniqueId(5);
        $in = [];

        $js->createOrUpdateObjectStore(new StoreConfig($in[] = $prefix . generateUniqueId(10)));
        $js->createOrUpdateObjectStore(new StoreConfig($in[] = $prefix . generateUniqueId(10)));
        $js->createOrUpdateObjectStore(new StoreConfig($in[] = $prefix . generateUniqueId(10)));

        $out = array_values(
            array_filter(
                [...$js->objectStoreNames()],
                static fn(string $store): bool => str_starts_with($store, $prefix),
            ),
        );

        sort($in);
        sort($out);

        self::assertEquals($in, $out);

        foreach ($in as $name) {
            $js->deleteObjectStore($name);
        }
    }

    public function testObjectStoreList(): void
    {
        $js = $this->client()->jetStream();

        $prefix = generateUniqueId(5);
        $in = [];

        $js->createOrUpdateObjectStore(new StoreConfig($in[] = $prefix . generateUniqueId(10)));
        $js->createOrUpdateObjectStore(new StoreConfig($in[] = $prefix . generateUniqueId(10)));
        $js->createOrUpdateObjectStore(new StoreConfig($in[] = $prefix . generateUniqueId(10)));

        $out = array_map(
            static fn(ObjectStoreInfo $info): string => $info->name,
            array_filter(
                [...$js->objectStoreList()],
                static fn(ObjectStoreInfo $info): bool => str_starts_with($info->name, $prefix),
            ),
        );

        sort($in);
        sort($out);

        self::assertEquals($in, $out);

        foreach ($in as $name) {
            $js->deleteObjectStore($name);
        }
    }

    public function testDeleteObjectStore(): void
    {
        $js = $this->client()->jetStream();

        $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        self::assertInstanceOf(Store::class, $js->objectStore($name));

        $js->deleteObjectStore($name);

        self::assertNull($js->objectStore($name));
    }

    public function testPutObject(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $info = $store->put(new ObjectMeta(name: 'xfile'), $body = str_repeat('x', 10));
        self::assertSame(10, $info->size);
        self::assertSame(1, $info->chunks);

        // Padded url-safe base64, as written by nats.go (base64.URLEncoding).
        self::assertSame(
            'SHA-256=' . strtr(base64_encode(hash('sha256', $body, true)), '+/', '-_'),
            $info->digest,
        );

        $object = $store->get('xfile');
        self::assertSame($body, (string) $object);

        $js->deleteObjectStore($name);
    }

    public function testDeleteObject(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $info = $store->put(new ObjectMeta(name: 'xfile'), str_repeat('x', 10));
        self::assertSame($info->nuid, $store->info('xfile')?->nuid);

        $store->delete('xfile');
        self::assertNull($store->info('xfile'));
        self::assertTrue($store->info('xfile', showDeleted: true)?->deleted);
        self::assertNull($store->get('xfile'));

        $js->deleteObjectStore($name);
    }

    public function testAddLink(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $info = $store->put(new ObjectMeta(name: 'xfile'), $body = str_repeat('x', 10));

        $store->addLink('yfile', $info);
        self::assertSame($body, (string) $store->get('yfile'));

        $js->deleteObjectStore($name);
    }

    public function testWatch(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $files = [];
        $count = 0;

        $subscription = $store->watch(static function (ObjectInfo $object, Subscription $subscription) use (
            &$files,
            &$count,
        ): void {
            $files[$object->name] = $object->size;

            if (++$count >= 3) {
                $subscription->stop();
            }
        });

        $store->put(new ObjectMeta('file1'), $body1 = str_repeat('x', 10));
        $store->put(new ObjectMeta('file2'), $body2 = str_repeat('y', 20));
        $store->put(new ObjectMeta('file3'), $body3 = str_repeat('z', 120));

        $subscription->awaitCompletion();

        self::assertSame(
            [
                'file1' => \strlen($body1),
                'file2' => \strlen($body2),
                'file3' => \strlen($body3),
            ],
            $files,
        );

        $js->deleteObjectStore($name);
    }

    public function testPutEmptyObject(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $info = $store->put(new ObjectMeta(name: 'empty'), '');
        self::assertSame(0, $info->size);
        self::assertSame(0, $info->chunks);
        self::assertSame('SHA-256=' . strtr(base64_encode(hash('sha256', '', true)), '+/', '-_'), $info->digest);

        self::assertSame($info->nuid, $store->info('empty')?->nuid);
        self::assertSame('', (string) $store->get('empty'));

        $handle = fopen('php://memory', 'r+');
        self::assertNotFalse($handle);
        $store->put(new ObjectMeta(name: 'empty-resource'), new ResourceReader($handle));
        self::assertSame(0, $store->info('empty-resource')?->size);
        self::assertSame('', (string) $store->get('empty-resource'));

        $js->deleteObjectStore($name);
    }

    public function testGetReadsChunksAndDeletesItsConsumer(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $info = $store->put(new ObjectMeta(name: 'xfile', chunkSize: 4), $body = 'abcdefghijklmnopqrstuvwxyz');
        self::assertSame(7, $info->chunks);

        self::assertSame($body, (string) $store->get('xfile'));
        self::assertSame($body, (string) $store->get('xfile'));

        $this->awaitNoConsumers($js, $name);

        $js->deleteObjectStore($name);
    }

    public function testGetFailsOnDigestMismatch(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $info = $store->put(new ObjectMeta(name: 'xfile'), str_repeat('x', 10));

        // Same size, other content.
        $js->purgeStream("OBJ_{$name}", subject: "\$O.{$name}.C.{$info->nuid}");
        $js->publish("\$O.{$name}.C.{$info->nuid}", new Message(str_repeat('y', 10)));

        $object = $store->get('xfile');
        self::assertNotNull($object);

        try {
            $body = $object->buffer();
            self::fail("A corrupted object must not be read, got '{$body}'.");
        } catch (ObjectIsInvalid $e) {
            self::assertSame("Object 'xfile' digest mismatch.", $e->getMessage());
        }

        $this->awaitNoConsumers($js, $name);

        $js->deleteObjectStore($name);
    }

    public function testGetFailsOnSizeMismatch(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $info = $store->put(new ObjectMeta(name: 'xfile'), str_repeat('x', 10));

        $js->publish("\$O.{$name}.C.{$info->nuid}", new Message('tail'));

        $this->expectException(ObjectIsInvalid::class);
        $this->expectExceptionMessage("Object 'xfile' size mismatch: expected 10 bytes, received 14.");

        try {
            (string) $store->get('xfile');
        } finally {
            $js->deleteObjectStore($name);
        }
    }

    public function testPutPurgesChunksWhenReaderFails(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        try {
            $store->put(new ObjectMeta(name: 'xfile', chunkSize: 8), self::failingReader());
            self::fail('The reader error must be rethrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('Disk is gone.', $e->getMessage());
        }

        self::assertNull($store->info('xfile'));
        self::assertSame(0, $js->streamInfo("OBJ_{$name}")->state->messages);

        $js->deleteObjectStore($name);
    }

    public function testPutPurgesChunksWhenPublishFails(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10), maxBytes: 2048));

        try {
            $store->put(new ObjectMeta(name: 'xfile', chunkSize: 256), str_repeat('x', 8192));
            self::fail('A put beyond the bucket limit must fail.');
        } catch (NatsException) {
        }

        self::assertNull($store->info('xfile'));
        self::assertSame(0, $js->streamInfo("OBJ_{$name}")->state->messages);

        $js->deleteObjectStore($name);
    }

    public function testInfoReturnsTheServerTimeWithSubsecondPrecision(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $store->put(new ObjectMeta(name: 'xfile'), 'x');

        $stored = $js->stream("OBJ_{$name}")
            ?->getLastMessageForSubject("\$O.{$name}.M." . strtr(base64_encode('xfile'), '+/', '-_'))
            ?->headers
            ?->get(Timestamp::Header);
        self::assertNotNull($stored);

        $info = $store->info('xfile');
        self::assertNotNull($info);
        self::assertSame($stored->format('Y-m-d\TH:i:s.uP'), $info->mtime->format('Y-m-d\TH:i:s.uP'));
        self::assertEqualsWithDelta(microtime(true), (float) $info->mtime->format('U.u'), 5);

        $js->deleteObjectStore($name);
    }

    public function testGetFailsWhenAllChunksAreGone(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $info = $store->put(new ObjectMeta(name: 'xfile'), str_repeat('x', 10));

        $js->purgeStream("OBJ_{$name}", subject: "\$O.{$name}.C.{$info->nuid}");

        try {
            $store->get('xfile');
            self::fail('An object without chunks must not be read.');
        } catch (ObjectIsInvalid $e) {
            self::assertSame("Object 'xfile' is missing chunks: expected 1, stored 0.", $e->getMessage());
        }

        $this->awaitNoConsumers($js, $name);

        $js->deleteObjectStore($name);
    }

    public function testGetFailsWhenSomeChunksAreGone(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $info = $store->put(new ObjectMeta(name: 'xfile', chunkSize: 4), 'abcdefghijklmnopqrstuvwxyz');

        $js->purgeStream("OBJ_{$name}", keep: 3, subject: "\$O.{$name}.C.{$info->nuid}");

        $this->expectException(ObjectIsInvalid::class);
        $this->expectExceptionMessage("Object 'xfile' is missing chunks: expected 7, stored 3.");

        try {
            $store->get('xfile');
        } finally {
            $js->deleteObjectStore($name);
        }
    }

    public function testReadFailsWhenChunksAreGoneBeforeDelivery(): void
    {
        $this->proxy = PausableProxy::start($this->natsAddress());
        $js = $this->clientWithUrls([$this->proxy->address])->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $info = $store->put(new ObjectMeta(name: 'xfile'), str_repeat('x', 10));

        // The consumer counts the chunk, but the server does not deliver it until the SUB of the reader arrives.
        $held = $this->proxy->pauseBefore('SUB ');
        $object = async(static fn(): ?StoredObject => $store->get('xfile'));
        $held->await(new TimeoutCancellation(self::TIMEOUT));

        $other = new Client($this->config());

        try {
            $other->jetStream()->purgeStream("OBJ_{$name}", subject: "\$O.{$name}.C.{$info->nuid}");
        } finally {
            $other->stop();
        }

        $this->proxy->resume();

        $read = async(static fn(): string => (string) $object->await());

        // Nothing is delivered then, the idle heartbeat tells that the chunk is gone.
        try {
            $read->await(new TimeoutCancellation(self::TIMEOUT * 2));
            self::fail('An object without chunks must not be read.');
        } catch (ObjectIsInvalid $e) {
            self::assertSame("Object 'xfile' is missing chunks: received 0 of 10 bytes.", $e->getMessage());
        }

        $this->awaitNoConsumers($js, $name);

        $js->deleteObjectStore($name);
    }

    public function testReadDoesNotWaitForConsumerDeletion(): void
    {
        $this->proxy = PausableProxy::start($this->natsAddress());
        $js = $this->clientWithUrls([$this->proxy->address])->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $store->put(new ObjectMeta(name: 'xfile'), $body = str_repeat('x', 10));

        $held = $this->proxy->pauseBefore('$JS.API.CONSUMER.DELETE.');

        $object = $store->get('xfile');
        self::assertNotNull($object);

        $read = async($object->buffer(...));
        self::assertSame($body, $read->await(new TimeoutCancellation(self::TIMEOUT)));

        // The deletion was sent after the read ended and is still held back.
        $held->await(new TimeoutCancellation(self::TIMEOUT));
        $this->proxy->resume();

        $this->awaitNoConsumers($js, $name);

        $js->deleteObjectStore($name);
    }

    public function testPutRethrowsWhenPurgeIsLost(): void
    {
        $this->proxy = PausableProxy::start($this->natsAddress());
        $js = $this->clientWithUrls([$this->proxy->address])->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        // The server never sees the purge, only the cleanup timeout ends the wait for it.
        $held = $this->proxy->pauseBefore('$JS.API.STREAM.PURGE.');
        $put = async(static fn(): ObjectInfo => $store->put(new ObjectMeta(name: 'xfile', chunkSize: 8), self::failingReader()));

        try {
            $put->await(new TimeoutCancellation(self::TIMEOUT * 2));
            self::fail('The reader error must be rethrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('Disk is gone.', $e->getMessage());
        }

        self::assertTrue($held->isComplete());
        $this->proxy->resume();

        $js->deleteObjectStore($name);
    }

    public function testListReturnsObjectsWithoutDeleted(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $a = $store->put(new ObjectMeta('a', description: 'first'), 'aaa');
        $store->put(new ObjectMeta('b'), 'bbb');
        $store->put(new ObjectMeta('c'), '');
        $store->addLink('link', $a);
        $store->delete('b');
        $store->put(new ObjectMeta('a', description: 'second'), 'aaaa');

        $objects = $store->list(cancellation: new TimeoutCancellation(self::TIMEOUT));

        self::assertSame(['c', 'link', 'a'], array_map(static fn(ObjectInfo $info): string => $info->name, $objects));
        self::assertSame('second', $objects[2]->description ?? null);
        self::assertSame(4, $objects[2]->size ?? null);
        self::assertTrue(($objects[1] ?? null)?->isLink());
        self::assertEqualsWithDelta($store->info('a')?->mtime->getTimestamp(), ($objects[2] ?? null)?->mtime->getTimestamp(), 1);

        $all = $store->list(showDeleted: true);

        self::assertSame(['c', 'link', 'b', 'a'], array_map(static fn(ObjectInfo $info): string => $info->name, $all));
        self::assertTrue($all[2]->deleted ?? null);

        $this->awaitNoConsumers($js, $name);

        $js->deleteObjectStore($name);
    }

    public function testListFailsWhenClientIsStoppedMidway(): void
    {
        $this->assertListFailsWhenInterrupted(static function (Client $client): void {
            $stop = async($client->stop(...));
            $stop->ignore();

            // The handler is held, so stop() waits for it: the subscription is stopped by now.
            delay(0.1);
        });
    }

    public function testListFailsWhenConnectionIsLost(): void
    {
        $this->assertListFailsWhenInterrupted(function (Client $client, Future $disconnected): void {
            $this->kick('object-store-list');
            $disconnected->await(new TimeoutCancellation(self::TIMEOUT));
        });
    }

    public function testListOfEmptyStore(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        self::assertSame([], $store->list());

        $store->put(new ObjectMeta('a'), 'aaa');
        $store->delete('a');

        self::assertSame([], $store->list());
        self::assertCount(1, $store->list(showDeleted: true));

        $this->awaitNoConsumers($js, $name);

        $js->deleteObjectStore($name);
    }

    public function testListManyObjects(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $names = [];
        for ($i = 0; $i < 300; ++$i) {
            $store->put(new ObjectMeta($names[] = "object-{$i}"), "{$i}");
        }

        self::assertSame(
            $names,
            array_map(static fn(ObjectInfo $info): string => $info->name, $store->list(cancellation: new TimeoutCancellation(self::TIMEOUT))),
        );

        $js->deleteObjectStore($name);
    }

    public function testUpdateMetaKeepsData(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $put = $store->put(new ObjectMeta('xfile', chunkSize: 4), $body = 'abcdefghij');

        $updated = $store->updateMeta('xfile', new ObjectMeta(
            name: 'xfile',
            description: 'updated',
            headers: ['X-Kind' => ['text', 'plain']],
            metadata: ['owner_team' => 'core', 'Build-Id' => '42'],
            chunkSize: 1024,
        ));

        self::assertSame('updated', $updated->description);
        self::assertSame($put->nuid, $updated->nuid);

        $info = $store->info('xfile');
        self::assertNotNull($info);
        self::assertSame('updated', $info->description);
        self::assertSame(['X-Kind' => ['text', 'plain']], $info->headers);
        self::assertSame(['owner_team' => 'core', 'Build-Id' => '42'], $info->metadata);
        self::assertSame(['owner_team' => 'core', 'Build-Id' => '42'], ($store->list()[0] ?? null)?->metadata);
        self::assertSame($put->digest, $info->digest);
        self::assertSame(3, $info->chunks);
        self::assertSame(4, $info->options?->maxChunkSize);

        self::assertSame($body, (string) $store->get('xfile'));

        $js->deleteObjectStore($name);
    }

    public function testUpdateMetaRenamesObject(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $store->put(new ObjectMeta('old'), $body = 'abcdefghij');

        // A deleted object does not hold its name.
        $store->put(new ObjectMeta('new'), 'gone');
        $store->delete('new');

        $store->updateMeta('old', new ObjectMeta('new'));

        self::assertNull($store->info('old'));
        self::assertNull($store->info('old', showDeleted: true));
        self::assertSame($body, (string) $store->get('new'));
        self::assertSame(['new'], array_map(static fn(ObjectInfo $info): string => $info->name, $store->list(showDeleted: true)));

        $js->deleteObjectStore($name);
    }

    public function testUpdateMetaDoesNotRenameOntoExistingObject(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $store->put(new ObjectMeta('a'), 'aaa');
        $store->put(new ObjectMeta('b'), 'bbb');

        try {
            $store->updateMeta('a', new ObjectMeta('b'));
            self::fail('The name of another object must not be taken.');
        } catch (ObjectAlreadyExists $e) {
            self::assertSame("Object 'b' already exists.", $e->getMessage());
        }

        self::assertSame('aaa', (string) $store->get('a'));
        self::assertSame('bbb', (string) $store->get('b'));

        $js->deleteObjectStore($name);
    }

    public function testUpdateMetaOfMissingObject(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $store->put(new ObjectMeta('deleted'), 'aaa');
        $store->delete('deleted');

        foreach (['missing', 'deleted'] as $object) {
            try {
                $store->updateMeta($object, new ObjectMeta($object, description: 'x'));
                self::fail("Object '{$object}' must not be updated.");
            } catch (ObjectNotFound $e) {
                self::assertSame("Object '{$object}' is not found.", $e->getMessage());
            }
        }

        self::assertNull($store->info('missing'));

        $js->deleteObjectStore($name);
    }

    public function testAddLinkDoesNotReplaceObject(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $a = $store->put(new ObjectMeta('a'), 'aaa');
        $store->put(new ObjectMeta('b'), 'bbb');

        $this->expectException(ObjectAlreadyExists::class);

        try {
            $store->addLink('b', $a);
        } finally {
            $js->deleteObjectStore($name);
        }
    }

    public function testStatus(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig(
            store: $name = generateUniqueId(10),
            description: 'my store',
            ttl: TimeSpan::fromHours(1),
            storageType: StorageType::Memory,
            compression: true,
            metadata: ['owner_team' => 'core', 'Build-Id' => '42'],
        ));

        $status = $store->status();
        self::assertSame($name, $status->bucket);
        self::assertSame('my store', $status->description);
        self::assertSame(3600, $status->ttl?->toSeconds());
        self::assertSame(StorageType::Memory, $status->storage);
        self::assertSame(1, $status->replicas);
        self::assertFalse($status->sealed);
        self::assertSame(0, $status->size);
        self::assertSame('JetStream', $status->backingStore);
        self::assertTrue($status->compressed);
        self::assertSame('core', $status->metadata['owner_team'] ?? null);
        self::assertSame('42', $status->metadata['Build-Id'] ?? null);
        self::assertArrayNotHasKey('ownerTeam', $status->metadata);
        $metadata = $status->metadata;

        $store->put(new ObjectMeta('xfile'), str_repeat('x', 100));
        $store->seal();

        $status = $store->status();
        self::assertTrue($status->sealed);
        // The config the server returned is sent back by seal(), the metadata must come back unchanged.
        self::assertSame($metadata, $status->metadata);
        self::assertGreaterThan(100, $status->size);

        $js->deleteObjectStore($name);
    }

    public function testStatusWithoutTtl(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $status = $store->status();
        self::assertNull($status->ttl);
        self::assertNull($status->description);
        self::assertFalse($status->compressed);
        self::assertSame(StorageType::File, $status->storage);

        $js->deleteObjectStore($name);
    }

    public function testPutFileAndGetFile(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $source = self::tempFile($body = random_bytes(300 * 1024));
        $target = self::tempFile('previous contents that are longer than nothing');

        try {
            $info = $store->putFile($source, new ObjectMeta('blob'));
            self::assertSame(300 * 1024, $info->size);
            self::assertSame(3, $info->chunks);

            self::assertSame($info->nuid, $store->getFile('blob', $target, new TimeoutCancellation(self::TIMEOUT))?->nuid);
            self::assertSame($body, file_get_contents($target));

            // Under its path by default, like nats.go.
            self::assertSame($source, $store->putFile($source)->name);
            self::assertSame($body, (string) $store->get($source));
        } finally {
            @unlink($source);
            @unlink($target);
            $js->deleteObjectStore($name);
        }
    }

    public function testGetFileOfMissingObjectDoesNotCreateFile(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $target = sys_get_temp_dir() . '/' . generateUniqueId(10);

        self::assertNull($store->getFile('missing', $target));
        self::assertFileDoesNotExist($target);

        $js->deleteObjectStore($name);
    }

    public function testGetFileKeepsExistingFileWhenReadFails(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $info = $store->put(new ObjectMeta(name: 'xfile'), str_repeat('x', 10));

        $js->purgeStream("OBJ_{$name}", subject: "\$O.{$name}.C.{$info->nuid}");
        $js->publish("\$O.{$name}.C.{$info->nuid}", new Message(str_repeat('y', 10)));

        $directory = sys_get_temp_dir() . '/' . generateUniqueId(10);
        mkdir($directory);
        $target = "{$directory}/xfile";
        file_put_contents($target, 'previous');

        try {
            $store->getFile('xfile', $target);
            self::fail('A corrupted object must not be written.');
        } catch (ObjectIsInvalid $e) {
            self::assertSame("Object 'xfile' digest mismatch.", $e->getMessage());
        }

        self::assertSame('previous', file_get_contents($target));
        self::assertSame(['xfile'], array_values(array_diff((array) scandir($directory), ['.', '..'])));

        unlink($target);
        rmdir($directory);

        $js->deleteObjectStore($name);
    }

    public function testFileIsNotAccessible(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        $store->put(new ObjectMeta('xfile'), 'x');

        $directory = sys_get_temp_dir() . '/';

        try {
            $store->putFile($directory);
            self::fail('A directory must not be put.');
        } catch (FileIsNotAccessible $e) {
            self::assertSame("The file '{$directory}' cannot be opened: not a regular file.", $e->getMessage());
        }

        self::assertNull($store->info($directory, showDeleted: true));

        $missing = sys_get_temp_dir() . '/' . generateUniqueId(10) . '/file';

        try {
            $store->putFile($missing);
            self::fail('A missing file must not be put.');
        } catch (FileIsNotAccessible $e) {
            self::assertStringStartsWith("The file '{$missing}' cannot be opened: ", $e->getMessage());
        }

        $this->expectException(FileIsNotAccessible::class);

        try {
            $store->getFile('xfile', $missing);
        } finally {
            $js->deleteObjectStore($name);
        }
    }

    public function testPutReadableStream(): void
    {
        $js = $this->client()->jetStream();

        $store = $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        $info = $store->put(
            new ObjectMeta('stream', chunkSize: 4),
            new ReadableIterableStream(['ab', 'cdefg', 'h', 'ij']),
        );
        self::assertSame(10, $info->size);
        self::assertSame(3, $info->chunks);

        $object = $store->get('stream');
        self::assertNotNull($object);

        // One object streamed into another.
        $copy = $store->put(new ObjectMeta('copy'), $object->stream);
        self::assertSame($info->digest, $copy->digest);
        self::assertSame('abcdefghij', (string) $store->get('copy'));

        $js->deleteObjectStore($name);
    }

    /**
     * Holds the list on the 10th of 100 metas until the interruption is done: the list must fail, not return a part.
     *
     * @param \Closure(Client, Future<void>): void $interrupt
     */
    private function assertListFailsWhenInterrupted(\Closure $interrupt): void
    {
        $count = 100;

        $setup = $this->client();
        $js = $setup->jetStream();
        $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));

        for ($i = 0; $i < $count; ++$i) {
            $js->objectStore($name)?->put(new ObjectMeta("object-{$i}"), '');
        }

        /** @var DeferredFuture<void> $disconnected */
        $disconnected = new DeferredFuture();

        $serializer = new class ($interrupt, $disconnected->getFuture()) implements Serializer {
            private readonly ValinorSerializer $serializer;

            private int $metas = 0;

            /**
             * @param \Closure(Client, Future<void>): void $interrupt
             * @param Future<void> $disconnected
             */
            public function __construct(
                private readonly \Closure $interrupt,
                private readonly Future $disconnected,
                public ?Client $client = null,
            ) {
                $this->serializer = new ValinorSerializer();
            }

            public function deserialize(string $type, iterable $data): mixed
            {
                if ($type === ObjectInfo::class && ++$this->metas === 10 && $this->client !== null) {
                    // The handler waits here, so no more metas are taken until the interruption is done.
                    async($this->interrupt, $this->client, $this->disconnected)->await();
                }

                return $this->serializer->deserialize($type, $data);
            }
        };

        $config = $this->config();
        $client = new Client(
            new Config(
                urls: $config->urls,
                user: $config->user,
                password: $config->password,
                clientName: 'object-store-list',
                reconnectWait: 0.1,
            ),
            serializer: $serializer,
        );
        $serializer->client = $client;
        $client->onDisconnect(static function () use ($disconnected): void {
            if (!$disconnected->isComplete()) {
                $disconnected->complete();
            }
        });

        $store = $client->jetStream()->objectStore($name);
        self::assertNotNull($store);

        try {
            $this->expectException(ObjectListIsIncomplete::class);
            $store->list(cancellation: new TimeoutCancellation(self::TIMEOUT));
        } finally {
            $client->stop();
            $js->deleteObjectStore($name);
        }
    }

    /**
     * @return non-empty-string
     */
    private static function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nats');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Gives two chunks, then fails.
     */
    private static function failingReader(): Reader
    {
        return new class implements Reader {
            private int $reads = 0;

            public function eof(): bool
            {
                return false;
            }

            public function read(int $length): string
            {
                if (++$this->reads > 2) {
                    throw new \RuntimeException('Disk is gone.');
                }

                return str_repeat('x', $length);
            }
        };
    }

    /**
     * The consumer of get() is deleted after the reader saw the end of the object.
     *
     * @param non-empty-string $store
     */
    private function awaitNoConsumers(JetStream $js, string $store): void
    {
        $deadline = microtime(true) + self::TIMEOUT;

        $count = $js->streamInfo("OBJ_{$store}")->state->consumerCount;

        while ($count > 0 && microtime(true) < $deadline) {
            delay(0.05);
            $count = $js->streamInfo("OBJ_{$store}")->state->consumerCount;
        }

        self::assertSame(0, $count);
    }
}
