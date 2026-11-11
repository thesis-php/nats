<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Revolt\EventLoop;
use Thesis\Nats\Client;
use Thesis\Nats\Config;
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
use Thesis\Nats\PausableProxy;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Amp\delay;
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

    public function testConsumeContinuesAfterReconnect(): void
    {
        $proxy = PausableProxy::start($this->natsAddress());
        $config = $this->config();
        // Stopped at the end: the client of the base test case is the publisher's.
        $nc = new Client(new Config(
            urls: [$proxy->address],
            user: $config->user,
            password: $config->password,
            reconnectWait: 0.1,
        ));
        $js = $nc->jetStream();
        $publisher = $this->client()->jetStream();

        $disconnected = new DeferredFuture();
        $nc->onDisconnect(static function () use ($disconnected): void {
            if (!$disconnected->isComplete()) {
                $disconnected->complete();
            }
        });

        /** @var DeferredFuture<void> $reconnected */
        $reconnected = new DeferredFuture();
        $nc->onReconnect(static function () use ($reconnected): void {
            if (!$reconnected->isComplete()) {
                $reconnected->complete();
            }
        });

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
        ));

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit),
        );

        /** @var \ArrayObject<int, string> $received */
        $received = new \ArrayObject();
        $subscription = $consumer->pull(static function (JetStreamDelivery $delivery) use ($received): void {
            $received[] = (string) $delivery->message->payload;
            $delivery->ack();
        });

        try {
            $publisher->publish("{$subject}.x", new Message('before'));
            self::waitUntil(static fn(): bool => \count($received) === 1);

            $proxy->kill();
            $disconnected->getFuture()->await(new TimeoutCancellation(5));

            // Nobody listens to the reply subject of the pull request now: the server drops the request.
            // Without pulling again, the message would wait for the missed heartbeats (2 x 15 seconds by default).
            $publisher->publish("{$subject}.x", new Message('while disconnected'));

            $restarted = PausableProxy::start($this->natsAddress(), $proxy->address);
            $reconnected->getFuture()->await(new TimeoutCancellation(5));
            self::waitUntil(static fn(): bool => \count($received) === 2);

            self::assertSame(['before', 'while disconnected'], $received->getArrayCopy());

            $subscription->stop();
            $stream->delete();
            $restarted->kill();
        } finally {
            $nc->stop();
            $proxy->kill();
        }
    }

    /**
     * @param non-negative-int $reconnectBufferSize without a buffer, a pull request sent while reconnecting fails at once,
     *                                              with one it would be sent after the reconnect, next to the reconnect's own
     */
    #[TestWith([0])]
    #[TestWith([1024 * 1024])]
    public function testHandlerFinishingWhileDisconnectedDoesNotPull(int $reconnectBufferSize): void
    {
        $proxy = PausableProxy::start($this->natsAddress());
        $config = $this->config();
        $nc = new Client(new Config(
            urls: [$proxy->address],
            user: $config->user,
            password: $config->password,
            reconnectWait: 0.1,
            reconnectBufferSize: $reconnectBufferSize,
        ));
        $js = $nc->jetStream();
        $publisher = $this->client()->jetStream();

        /** @var DeferredFuture<void> $disconnected */
        $disconnected = new DeferredFuture();
        $nc->onDisconnect(static function () use ($disconnected): void {
            if (!$disconnected->isComplete()) {
                $disconnected->complete();
            }
        });

        /** @var DeferredFuture<void> $reconnected */
        $reconnected = new DeferredFuture();
        $nc->onReconnect(static function () use ($reconnected): void {
            if (!$reconnected->isComplete()) {
                $reconnected->complete();
            }
        });

        /** @var \ArrayObject<int, \Throwable> $loopErrors */
        $loopErrors = new \ArrayObject();
        $errorHandler = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static fn(\Throwable $e) => $loopErrors->append($e));

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);
        $durable = generateUniqueId(10);
        $stream = $js->createStream(new StreamConfig(name: $streamName, subjects: ["{$subject}.*"]));
        $consumer = $stream->createConsumer(new ConsumerConfig(durableName: $durable, ackPolicy: AckPolicy::None));

        // The pull requests reach the subscribers of the API subject as well.
        $observer = new Client($this->config());
        $pulls = new \ArrayObject();
        $observer->subscribe("\$JS.API.CONSUMER.MSG.NEXT.{$streamName}.{$durable}", static fn() => $pulls->append(true));
        $observer->ping();

        /** @var DeferredFuture<void> $started */
        $started = new DeferredFuture();

        /** @var DeferredFuture<void> $gate */
        $gate = new DeferredFuture();

        /** @var \ArrayObject<int, string> $received */
        $received = new \ArrayObject();
        $subscription = $consumer->pull(static function (JetStreamDelivery $delivery) use (
            $received,
            $started,
            $gate,
        ): void {
            if (!$started->isComplete()) {
                $started->complete();
                $gate->getFuture()->await();
            }

            $received[] = (string) $delivery->message->payload;
        }, new PullConsumeConfig(maxMessages: 1));

        try {
            $publisher->publish("{$subject}.x", new Message('first'));
            $started->getFuture()->await(new TimeoutCancellation(5));

            $proxy->kill();
            $disconnected->getFuture()->await(new TimeoutCancellation(5));

            // The handler finishes while there is no connection: nothing must be pulled until the reconnect.
            $gate->complete();
            delay(0.3);
            self::assertCount(0, $loopErrors, implode("\n", array_map(static fn(\Throwable $e): string => (string) $e, $loopErrors->getArrayCopy())));

            $restarted = PausableProxy::start($this->natsAddress(), $proxy->address);
            $reconnected->getFuture()->await(new TimeoutCancellation(5));

            // The first pull and the full batch the reconnect pulls, nothing pulled while disconnected.
            $observer->ping();
            delay(0.3);
            self::assertCount(2, $pulls, 'A pull request was sent while disconnected.');

            $publisher->publish("{$subject}.x", new Message('second'));
            self::waitUntil(static fn(): bool => \count($received) === 2);
            self::assertSame(['first', 'second'], $received->getArrayCopy());
            self::assertFalse($subscription->completed());

            $subscription->stop();
            $stream->delete();
            $restarted->kill();
        } finally {
            EventLoop::setErrorHandler($errorHandler);
            $observer->stop();
            $nc->stop();
            $proxy->kill();
        }
    }

    /**
     * @param \Closure(): bool $condition
     */
    private static function waitUntil(\Closure $condition): void
    {
        $deadline = microtime(true) + 5;

        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail('The condition was not met in time.');
            }

            delay(0.01);
        }
    }

    public function testStopFromHandlerDoesNotPullAfterwards(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
        ));

        $js->publish("{$subject}.x", new Message('x'));

        $consumer = $stream->createConsumer(
            new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit),
        );

        $subscription = $consumer->pulling()->consume(
            static function (JetStreamDelivery $delivery, Subscription $subscription): void {
                $delivery->ack();
                $subscription->stop();
            },
            new PullConsumeConfig(expires: TimeSpan::fromSeconds(1)),
        );

        $subscription->awaitCompletion();

        try {
            // The missed heartbeats watchdog (2 x 500ms) must not be rescheduled after the consumer stopped,
            // otherwise it pulls into the completed queue and fails the event loop.
            delay(1.5);

            self::assertTrue($subscription->completed());
            self::assertSame(0, $consumer->actualInfo()->numAckPending);
        } finally {
            $stream->delete();
        }
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
            handler: static function (Delivery $delivery) use (
                $nc,
                $status,
                $description,
                &$pulls,
                $repulled,
            ): void {
                /** @var array{batch: int, ...} $pull */
                $pull = json_decode($delivery->message->payload ?? '', true, flags: JSON_THROW_ON_ERROR);

                if (++$pulls === 1) {
                    \assert($delivery->replyTo !== null);

                    $nc->publish($delivery->replyTo, new Message(headers: new Headers()
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
