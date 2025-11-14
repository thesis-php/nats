<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\Message;
use Thesis\Nats\NatsTestCase;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(PullConsumer::class)]
final class PullConsumerTest extends NatsTestCase
{
    public function testAckDelivery(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        $js->publish("{$subject}.x", new Message('x'));

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit),
        );

        self::assertSame(1, $consumer->actualInfo()->numPending);

        $subscription = $consumer->pull(static function (JetStreamDelivery $delivery, Subscription $subscription): void {
            $delivery->ack();
            $subscription->stop();
        });

        $subscription->awaitCompletion();

        self::assertSame(0, $consumer->actualInfo()->numPending);
        self::assertSame(0, $consumer->actualInfo()->numRedelivered);

        $stream->delete();
    }

    public function testNackDelivery(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        $js->publish("{$subject}.x", new Message('x'));

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit),
        );

        self::assertSame(1, $consumer->actualInfo()->numPending);

        $subscription = $consumer->pull(static function (JetStreamDelivery $delivery, Subscription $subscription): void {
            $delivery->nack();
            $subscription->stop();
        });

        $subscription->awaitCompletion();

        self::assertSame(0, $consumer->actualInfo()->numPending);
        self::assertSame(1, $consumer->actualInfo()->numRedelivered);

        $stream->delete();
    }

    public function testNackWithDelayDelivery(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        $js->publish("{$subject}.x", new Message('x'));

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit),
        );

        self::assertSame(1, $consumer->actualInfo()->numPending);

        $count = 0;

        $subscription = $consumer->pull(static function (JetStreamDelivery $delivery, Subscription $subscription) use (&$count): void {
            ++$count;

            if ($count > 1) {
                $delivery->terminate('unprocessable');
                $subscription->stop();
            } else {
                $delivery->nack(TimeSpan::fromMilliseconds(200));
            }
        });

        $subscription->awaitCompletion();

        self::assertSame(2, $count);
        self::assertSame(0, $consumer->actualInfo()->numPending);
        self::assertSame(0, $consumer->actualInfo()->numRedelivered);

        $stream->delete();
    }

    public function testFetchBatch(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        for ($i = 0; $i < 10; ++$i) {
            $js->publish("{$subject}.{$i}", new Message("{$i}"));
        }

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit),
        );

        self::assertSame(10, $pending = $consumer->actualInfo()->numPending);

        for ($i = 0; $i < 2; ++$i) {
            $count = 0;

            $deliveries = $consumer
                ->pulling()
                ->fetch(FetchConfig::batch(5));

            foreach ($deliveries as $delivery) {
                ++$count;
                $delivery->ack();
            }

            $pending -= $count;
            self::assertSame(5, $count);
            self::assertSame($pending, $consumer->actualInfo()->numPending);
        }

        $stream->delete();
    }

    public function testFetchBytes(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        for ($i = 0; $i < 10; ++$i) {
            $js->publish("{$subject}.{$i}", new Message("{$i}"));
        }

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit),
        );

        self::assertSame(10, $consumer->actualInfo()->numPending);

        $tooSmallBatch = [...$consumer->pulling()->fetch(FetchConfig::bytes(20))];

        self::assertCount(0, $tooSmallBatch);

        $deliveries = $consumer
            ->pulling()
            ->fetch(FetchConfig::bytes(300));

        $count = 0;

        foreach ($deliveries as $delivery) {
            ++$count;
            $delivery->ack();
        }

        self::assertSame(3, $count);
        self::assertSame(7, $consumer->actualInfo()->numPending);

        $stream->delete();
    }

    public function testFetchImmediate(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit),
        );

        $noMessagesBatch = [...$consumer->pulling()->fetch(FetchConfig::immediate())];
        self::assertCount(0, $noMessagesBatch);

        for ($i = 0; $i < 10; ++$i) {
            $js->publish("{$subject}.{$i}", new Message("{$i}"));
        }

        self::assertSame(10, $consumer->actualInfo()->numPending);

        $deliveries = [...$consumer
            ->pulling()
            ->fetch(FetchConfig::immediate()),
        ];

        $count = 0;

        foreach ($deliveries as $delivery) {
            ++$count;
            $delivery->ack();
        }

        self::assertSame(10, $count);
        self::assertSame(0, $consumer->actualInfo()->numPending);

        $stream->delete();
    }
}
