<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\NatsTestCase;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(Bucket::class)]
final class KeyValueTest extends NatsTestCase
{
    public function testCreateKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig($bucket = generateUniqueId(10)));
        self::assertNull($kv->get('invalid'));
        self::assertNotNull($js->keyValue($bucket));
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

        $kv = $js->createOrUpdateKeyValue(new BucketConfig(generateUniqueId(10)));

        $ts = new \DateTimeImmutable();
        self::assertSame(1, $kv->put('x', 'y'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);
        self::assertSame("KV_{$kv->name}", $entry->bucket);
        self::assertSame('x', $entry->key);
        self::assertEquals('y', $entry->value);
        self::assertSame(1, $entry->sequence);
        self::assertGreaterThanOrEqual($ts->getTimestamp(), $entry->created->getTimestamp());
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
    }

    public function testDeleteBucketKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig(generateUniqueId(10)));

        self::assertSame(1, $kv->put('x', 'y'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);

        $kv->delete('x');
        self::assertNull($kv->get('x'));
    }

    public function testPurgeBucketKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig(generateUniqueId(10)));

        self::assertSame(1, $kv->put('x', 'y'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);

        $kv->purge('x');
        self::assertNull($kv->get('x'));
    }

    public function testUpdateBucketKeyValue(): void
    {
        $js = $this->client()->jetStream();

        $kv = $js->createOrUpdateKeyValue(new BucketConfig(generateUniqueId(10)));

        self::assertSame(1, $kv->put('x', 'y'));

        $entry = $kv->get('x');
        self::assertNotNull($entry);
        self::assertSame('y', $entry->value);

        $kv->update('x', $entry->sequence, 'z');

        $entry = $kv->get('x');
        self::assertNotNull($entry);
        self::assertSame('z', $entry->value);
    }
}
