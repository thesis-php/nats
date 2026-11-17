<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\ConsumerAlreadyConsuming;
use Thesis\Nats\Exception\ConsumerSequenceMismatch;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\Message;
use Thesis\Nats\NatsTestCase;
use Thesis\Nats\PendingLimits;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Amp\delay;
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
            // Nack the first delivery and stop only once it is redelivered: stopping right after the nack
            // races with the server, which does not redeliver if the unsubscribe wins.
            if ($delivery->metadata?->delivered === 1) {
                $delivery->nack();

                return;
            }

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

    public function testHeartbeatRevealsDroppedMessages(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
        ));

        for ($i = 1; $i <= 5; ++$i) {
            $js->publish("{$subject}.x", new Message("m{$i}"));
        }

        /** @var \ArrayObject<int, ConsumerSequenceMismatch> $mismatches */
        $mismatches = new \ArrayObject();
        $nc->onError(static function (\Throwable $e) use ($mismatches): void {
            if ($e instanceof ConsumerSequenceMismatch) {
                $mismatches[] = $e;
            }
        });

        $consumer = $stream->createConsumer(new ConsumerConfig(
            durableName: generateUniqueId(10),
            deliverSubject: generateInboxId(),
            ackPolicy: AckPolicy::None,
            idleHeartbeat: TimeSpan::fromMilliseconds(500),
        ));

        /** @var \ArrayObject<int, int> $received */
        $received = new \ArrayObject();
        $subscription = $consumer->push(
            static function (JetStreamDelivery $delivery) use ($received): void {
                if ($received->count() === 0) {
                    delay(0.2);
                }

                $received[] = $delivery->metadata->consumerSequence ?? 0;
            },
            // The first message is in the handler, the next one waits, the rest is dropped.
            new PushConsumeConfig(pendingLimits: new PendingLimits(messages: 1, bytes: null)),
        );

        $deadline = microtime(true) + 5;

        while (\count($mismatches) === 0 && microtime(true) < $deadline) {
            delay(0.05);
        }

        // Every next heartbeat names the same sequence: reported once.
        delay(1.2);
        $subscription->stop();

        self::assertCount(1, $mismatches);
        $mismatch = $mismatches[0] ?? self::fail('No mismatch reported.');
        self::assertSame($stream->name, $mismatch->stream);
        self::assertSame($consumer->name, $mismatch->consumer);
        self::assertSame(5, $mismatch->lastConsumerSequence);
        self::assertSame(max([0, ...$received->getArrayCopy()]), $mismatch->consumerSequence);
        self::assertLessThan(5, $mismatch->consumerSequence);
        self::assertSame($mismatch->consumerSequence + 1, $mismatch->streamResumeSequence);

        $stream->delete();
    }
}
