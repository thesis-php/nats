<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Thesis\Nats\Delivery;
use Thesis\Nats\Description;
use Thesis\Nats\Exception\MaxRequestBatchExceeded;
use Thesis\Nats\Exception\MaxRequestExpiresExceeded;
use Thesis\Nats\Exception\MaxRequestMaxBytesExceeded;
use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\Header\StatusDescription;
use Thesis\Nats\Headers;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\NatsTestCase;
use Thesis\Nats\Status;
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

    /**
     * @param non-empty-string $description
     */
    #[DataProvider('provideConsumePullsAgainAfterServerDroppedPullRequestsCases')]
    public function testConsumePullsAgainAfterServerDroppedPullRequests(Status $status, string $description): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);
        $consumerName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: $consumerName, ackPolicy: AckPolicy::Explicit),
        );

        $pulls = 0;
        /** @var DeferredFuture<int> $repulled */
        $repulled = new DeferredFuture();

        // Observe the pull requests alongside the server and answer the first one as the server
        // does when it drops waiting requests, e.g. on a consumer leader change.
        $observer = $nc->subscribe(
            subject: "\$JS.API.CONSUMER.MSG.NEXT.{$streamName}.{$consumerName}",
            handler: static function (Delivery $delivery) use ($nc, $status, $description, &$pulls, $repulled): void {
                /** @var array{batch: int, ...} $pull */
                $pull = json_decode($delivery->message->payload ?? '', true, flags: JSON_THROW_ON_ERROR);

                if (++$pulls === 1) {
                    \assert($delivery->replyTo !== null);

                    $nc->publish($delivery->replyTo, new Message(headers: (new Headers())
                        ->with(StatusCode::Header, $status)
                        ->with(StatusDescription::Header, new Description($description))));
                } elseif (!$repulled->isComplete()) {
                    $repulled->complete($pull['batch']);
                }
            },
        );

        $subscription = $consumer->pull(static function (): void {});

        // Without a new pull the consumer would wait for the missed heartbeats, i.e. 30 seconds by default.
        $batch = $repulled->getFuture()->await(new TimeoutCancellation(5));

        self::assertSame(500, $batch);

        $subscription->stop();
        $observer->stop();
        $stream->delete();
    }

    /**
     * @return iterable<string, array{Status, non-empty-string}>
     */
    public static function provideConsumePullsAgainAfterServerDroppedPullRequestsCases(): iterable
    {
        yield 'pin id mismatch' => [Status::PinIdMismatch, 'Nats-Pin-Id mismatch'];
        yield 'leadership change' => [Status::Conflict, 'Leadership Change'];
    }

    /**
     * @param class-string<NatsException> $exception
     */
    #[DataProvider('provideConsumeFailsWhenServerRejectsPullRequestsCases')]
    public function testConsumeFailsWhenServerRejectsPullRequests(
        ConsumerConfig $consumerConfig,
        PullConsumeConfig $consumeConfig,
        string $exception,
    ): void {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        $consumer = $stream->createConsumer($consumerConfig);

        $subscription = $consumer->pull(static function (): void {}, $consumeConfig);

        try {
            $subscription->awaitCompletion(new TimeoutCancellation(5));
            self::fail('The subscription must fail.');
        } catch (NatsException $e) {
            self::assertInstanceOf($exception, $e);
        } finally {
            $stream->delete();
        }
    }

    /**
     * @return iterable<string, array{ConsumerConfig, PullConsumeConfig, class-string<NatsException>}>
     */
    public static function provideConsumeFailsWhenServerRejectsPullRequestsCases(): iterable
    {
        yield 'max request batch' => [
            new ConsumerConfig(durableName: generateUniqueId(10), maxRequestBatch: 10),
            new PullConsumeConfig(maxMessages: 100),
            MaxRequestBatchExceeded::class,
        ];

        yield 'max request expires' => [
            new ConsumerConfig(durableName: generateUniqueId(10), maxRequestExpires: TimeSpan::fromSeconds(5)),
            new PullConsumeConfig(expires: TimeSpan::fromSeconds(10)),
            MaxRequestExpiresExceeded::class,
        ];

        yield 'max request max bytes' => [
            new ConsumerConfig(durableName: generateUniqueId(10), maxRequestMaxBytes: 100),
            new PullConsumeConfig(maxBytes: 1_000),
            MaxRequestMaxBytesExceeded::class,
        ];
    }
}
