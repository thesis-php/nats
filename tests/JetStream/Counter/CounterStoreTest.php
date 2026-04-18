<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Counter;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\NatsTestCase;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(CounterStore::class)]
final class CounterStoreTest extends NatsTestCase
{
    public function testAdd(): void
    {
        $js = $this->client()->jetStream();

        $counter = $js->createOrUpdateCounter(new CounterConfig(name: $name = generateUniqueId(10)));
        self::assertSame(1, $counter->add('x', 1));
        self::assertSame(2, $counter->add('x', 1));
        self::assertSame(3, $counter->add('x', 1));
        self::assertSame(2, $counter->add('x', -1));

        $js->deleteCounter($name);
    }

    public function testAddGet(): void
    {
        $js = $this->client()->jetStream();

        $counter = $js->createOrUpdateCounter(new CounterConfig(name: $name = generateUniqueId(10)));
        self::assertSame(1, $counter->add('x', 1));
        self::assertSame(3, $counter->add('x', 2));

        $entry = $counter->get('x');
        self::assertNotNull($entry);
        self::assertSame('x', $entry->subject);
        self::assertSame(3, $entry->value);
        self::assertSame(2, $entry->incr);

        $js->deleteCounter($name);
    }

    public function testGetMultiple(): void
    {
        $js = $this->client()->jetStream();

        $counter = $js->createOrUpdateCounter(new CounterConfig(name: $name = generateUniqueId(10)));
        self::assertSame(1, $counter->add('x', 1));
        self::assertSame(2, $counter->add('y', 2));

        $entries = iterator_to_array($counter->getMultiple(), preserve_keys: false);
        self::assertCount(2, $entries);
        self::assertEquals(new Entry('x', 1, 1), $entries[0]);
        self::assertEquals(new Entry('y', 2, 2), $entries[1]);

        $entries = iterator_to_array($counter->getMultiple(['x']), preserve_keys: false);
        self::assertCount(1, $entries);
        self::assertEquals(new Entry('x', 1, 1), $entries[0]);

        $entries = iterator_to_array($counter->getMultiple(['z']), preserve_keys: false);
        self::assertCount(0, $entries);

        $js->deleteCounter($name);
    }

    public function testGetUnknownCounter(): void
    {
        $js = $this->client()->jetStream();

        $counter = $js->createOrUpdateCounter(new CounterConfig(name: $name = generateUniqueId(10)));
        $entry = $counter->get('x');
        self::assertNull($entry);
        $js->deleteCounter($name);
    }
}
