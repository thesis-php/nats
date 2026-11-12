<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Client;
use Thesis\Nats\Exception\ObjectIsInvalid;
use Thesis\Nats\Header\Timestamp;
use Thesis\Nats\JetStream;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\NatsTestCase;
use Thesis\Nats\PausableProxy;
use Thesis\Nats\Subscription;
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
        self::assertTrue($store->info('xfile')?->deleted);

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
