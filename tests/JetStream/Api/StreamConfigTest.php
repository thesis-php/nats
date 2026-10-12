<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;

#[CoversClass(StreamConfig::class)]
#[CoversClass(StreamSource::class)]
final class StreamConfigTest extends TestCase
{
    public function testValid(): void
    {
        $config = new StreamConfig(
            name: 'ORDERS',
            subjects: ['orders.*', 'archive.>'],
            mirror: new StreamSource('MIRROR'),
            sources: [new StreamSource('SOURCE', filterSubject: 'orders.>')],
        );

        self::assertSame('ORDERS', $config->seal()->name);
    }

    public function testInvalidName(): void
    {
        $this->expectException(InvalidName::class);
        new StreamConfig(name: 'orders.v1');
    }

    public function testInvalidSubject(): void
    {
        $this->expectExceptionObject(InvalidSubject::because('orders..*', 'it contains an empty token'));
        new StreamConfig(name: 'ORDERS', subjects: ['orders.>', 'orders..*']);
    }

    public function testInvalidSourceName(): void
    {
        $this->expectException(InvalidName::class);
        new StreamSource('my mirror');
    }

    public function testInvalidSourceFilter(): void
    {
        $this->expectException(InvalidSubject::class);
        new StreamSource('SOURCE', filterSubject: '>.orders');
    }

    public function testEmptySourceFilterMeansNone(): void
    {
        self::assertSame('', (new StreamSource('SOURCE', filterSubject: ''))->filterSubject);
    }
}
