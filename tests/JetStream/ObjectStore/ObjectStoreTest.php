<?php

declare(strict_types=1);

namespace JetStream\ObjectStore;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\JetStream\ObjectStore\ObjectStoreInfo;
use Thesis\Nats\JetStream\ObjectStore\Store;
use Thesis\Nats\JetStream\ObjectStore\StoreConfig;
use Thesis\Nats\NatsTestCase;
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
    }

    public function testDeleteObjectStore(): void
    {
        $js = $this->client()->jetStream();

        $js->createOrUpdateObjectStore(new StoreConfig($name = generateUniqueId(10)));
        self::assertInstanceOf(Store::class, $js->objectStore($name));

        $js->deleteObjectStore($name);

        self::assertNull($js->objectStore($name));
    }
}
