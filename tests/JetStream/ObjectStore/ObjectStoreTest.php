<?php

declare(strict_types=1);

namespace JetStream\ObjectStore;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\JetStream\ObjectStore\ObjectInfo;
use Thesis\Nats\JetStream\ObjectStore\ObjectMeta;
use Thesis\Nats\JetStream\ObjectStore\ObjectStoreInfo;
use Thesis\Nats\JetStream\ObjectStore\Store;
use Thesis\Nats\JetStream\ObjectStore\StoreConfig;
use Thesis\Nats\NatsTestCase;
use function Amp\async;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(Store::class)]
final class ObjectStoreTest extends NatsTestCase
{
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

        $objects = $store->watch();

        $future = async(static function () use (&$files, $objects): void {
            $count = 0;

            /** @var ObjectInfo $object */
            foreach ($objects as $object) {
                $files[$object->name] = $object->size;

                if (++$count >= 3) {
                    return;
                }
            }
        });

        $store->put(new ObjectMeta('file1'), $body1 = str_repeat('x', 10));
        $store->put(new ObjectMeta('file2'), $body2 = str_repeat('y', 20));
        $store->put(new ObjectMeta('file3'), $body3 = str_repeat('z', 120));

        $future->await();
        $objects->complete();

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
}
