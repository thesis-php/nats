<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\PublishBatchAlreadyCommitted;
use Thesis\Nats\JetStream\Api\StreamConfig;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(PublishBatch::class)]
final class PublishBatchTest extends NatsTestCase
{
    public function testPublishBatchOnJetStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: [
                $subject = generateUniqueId(10),
            ],
            allowAtomicPublish: true,
        ));

        self::assertSame(0, $stream->actualInfo()->state->messages);

        $js->publishBatch($subject, array_map(
            static fn(int $n): Message => new Message("Message#{$n}"),
            range(0, 10),
        ));

        self::assertSame(11, $stream->actualInfo()->state->messages);

        $stream->delete();
    }

    public function testPublishBatch(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: [
                $subject = generateUniqueId(10),
            ],
            allowAtomicPublish: true,
        ));

        self::assertSame(0, $stream->actualInfo()->state->messages);

        $batch = $js->createPublishBatch();

        for ($i = 0; $i < 10; ++$i) {
            $batch->publish($subject, new Message("Message#{$i}"));
        }

        $batch->publish($subject, new Message('Message#10'), new PublishBatchOptions(commit: true));

        self::assertSame(11, $stream->actualInfo()->state->messages);

        $stream->delete();
    }

    public function testPublishAlreadyCommittedBatch(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: [
                $subject = generateUniqueId(10),
            ],
            allowAtomicPublish: true,
        ));

        $batch = $js->createPublishBatch();

        $batch->publish($subject, new Message('Message#1'), new PublishBatchOptions(commit: true));

        self::expectException(PublishBatchAlreadyCommitted::class);
        $batch->publish($subject, new Message('Message#1'));
    }
}
