<?php

declare(strict_types=1);

namespace JetStream;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\ConsumerAlreadyConsuming;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\Message;
use Thesis\Nats\NatsTestCase;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Thesis\Nats\Internal\Id\generateInboxId;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(PushConsumerTest::class)]
final class PushConsumerTest extends NatsTestCase
{
    public function testDeliverySubjectIsRequired(): void
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

        self::expectException(\LogicException::class);
        self::expectExceptionMessage('For push consumers deliver subject is required.');
        $consumer->pushing()->drain();
    }

    public function testOneConsumerPerPush(): void
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
            new ConsumerConfig(
                durableName: generateUniqueId(10),
                deliverSubject: generateInboxId(),
                ackPolicy: AckPolicy::Explicit,
                maxAckPending: 1,
            ),
        );

        $pushing = $consumer->pushing();

        $pushing->consume(static function (): void {});

        self::expectException(ConsumerAlreadyConsuming::class);
        $pushing->consume(static function (): void {});
    }

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
            new ConsumerConfig(
                durableName: generateUniqueId(10),
                deliverSubject: generateInboxId(),
                ackPolicy: AckPolicy::Explicit,
                maxAckPending: 1,
            ),
        );

        self::assertSame(1, $consumer->actualInfo()->numPending);

        $subscription = $consumer->push(static function (JetStreamDelivery $delivery, Subscription $subscription): void {
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
            new ConsumerConfig(
                durableName: generateUniqueId(10),
                deliverSubject: generateInboxId(),
                ackPolicy: AckPolicy::Explicit,
                maxAckPending: 1,
            ),
        );

        self::assertSame(1, $consumer->actualInfo()->numPending);

        $subscription = $consumer->push(static function (JetStreamDelivery $delivery, Subscription $subscription): void {
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
            new ConsumerConfig(
                durableName: generateUniqueId(10),
                deliverSubject: generateInboxId(),
                ackPolicy: AckPolicy::Explicit,
            ),
        );

        self::assertSame(1, $consumer->actualInfo()->numPending);

        $count = 0;

        $subscription = $consumer->push(static function (JetStreamDelivery $delivery, Subscription $subscription) use (&$count): void {
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
