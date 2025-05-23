<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(Metadata::class)]
final class MetadataTest extends TestCase
{
    /**
     * @param non-empty-string $subject
     */
    #[TestWith(
        [
            '$JS.ACK.EventsStream.EventsConsumer.1.1.1.1747976767996376564.0',
            new Metadata(
                stream: 'EventsStream',
                consumer: 'EventsConsumer',
                delivered: 1,
                streamSequence: 1,
                consumerSequence: 1,
                timestamp: new \DateTimeImmutable('@1747976768'),
                pending: 0,
            ),
        ],
    )]
    #[TestWith(
        [
            '$JS.ACK.test.xxx.EventsStream.EventsConsumer.1.1.1.1747976767996376564.0',
            new Metadata(
                stream: 'EventsStream',
                consumer: 'EventsConsumer',
                delivered: 1,
                streamSequence: 1,
                consumerSequence: 1,
                timestamp: new \DateTimeImmutable('@1747976768'),
                pending: 0,
                domain: 'test',
                hash: 'xxx',
            ),
        ],
    )]
    #[TestWith(
        [
            '$JS.ACK.test.xxx.EventsStream.EventsConsumer.1.1.1.1747976767996376564.0.random',
            new Metadata(
                stream: 'EventsStream',
                consumer: 'EventsConsumer',
                delivered: 1,
                streamSequence: 1,
                consumerSequence: 1,
                timestamp: new \DateTimeImmutable('@1747976768'),
                pending: 0,
                domain: 'test',
                hash: 'xxx',
                token: 'random',
            ),
        ],
    )]
    public function testParseMetadata(string $subject, Metadata $metadata): void
    {
        self::assertEquals($metadata, Metadata::parse($subject));
    }

    public function testParseInvalidPrefix(): void
    {
        self::expectExceptionObject(new \InvalidArgumentException("Invalid subject format: no leading '\$JS.ACK.' prefix."));
        Metadata::parse('EventsStream.EventsConsumer.1.1.1.1747976767996376564.0');
    }

    public function testParseInvalidLength(): void
    {
        self::expectExceptionObject(new \InvalidArgumentException('Invalid subject format: incorrect length.'));
        Metadata::parse('$JS.ACK.EventsStream.EventsConsumer.1.1.1');
    }
}
