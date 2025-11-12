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
}
