<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Client;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\ConsumerAlreadyConsuming;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\Message;
use Thesis\Nats\NatsTestCase;
use Thesis\Nats\PausableProxy;
use Thesis\Nats\PendingLimits;
use Thesis\Time\TimeSpan;
use function Amp\delay;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(OrderedConsumer::class)]
#[CoversClass(OrderedConsumerConfig::class)]
#[CoversClass(Internal\Ordered\OrderedMessageHandler::class)]
final class OrderedConsumerTest extends NatsTestCase
{
    public function testConsumesInOrderAndDeletesTheConsumerOnStop(): void
    {
        $js = $this->client()->jetStream();
        [$stream, $subject] = self::createStream($js);

        for ($i = 1; $i <= 5; ++$i) {
            $js->publish("{$subject}.{$i}", new Message("m{$i}"));
        }

        $consumer = $stream->orderedConsumer();

        /** @var \ArrayObject<int, string> $received */
        $received = new \ArrayObject();
        $subscription = $consumer->consume(static function (JetStreamDelivery $delivery) use ($received): void {
            $received[] = (string) $delivery->message->payload;
        });

        self::waitUntil(static fn(): bool => \count($received) === 5);
        self::assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $received->getArrayCopy());
        self::assertCount(1, [...$js->consumerNames($stream->name)]);

        $subscription->stop();

        self::waitUntil(static fn(): bool => [...$js->consumerNames($stream->name)] === []);

        $stream->delete();
    }

    public function testConsumeIterator(): void
    {
        $js = $this->client()->jetStream();
        [$stream, $subject] = self::createStream($js);

        for ($i = 1; $i <= 3; ++$i) {
            $js->publish("{$subject}.x", new Message("m{$i}"));
        }

        $iterator = $stream->orderedConsumer()->consumeIterator();

        $received = [];
        foreach ($iterator as $delivery) {
            $received[] = $delivery->metadata?->streamSequence;

            if (\count($received) === 3) {
                $iterator->stop();
            }
        }

        self::assertSame([1, 2, 3], $received);

        $stream->delete();
    }

    public function testNextConsumeContinuesAfterTheLastMessage(): void
    {
        $js = $this->client()->jetStream();
        [$stream, $subject] = self::createStream($js);

        for ($i = 1; $i <= 3; ++$i) {
            $js->publish("{$subject}.x", new Message("m{$i}"));
        }

        $consumer = $stream->orderedConsumer();

        /** @var \ArrayObject<int, ?int> $received */
        $received = new \ArrayObject();
        $handler = static function (JetStreamDelivery $delivery) use ($received): void {
            $received[] = $delivery->metadata?->streamSequence;
        };

        $subscription = $consumer->consume($handler);
        self::waitUntil(static fn(): bool => \count($received) === 3);
        $subscription->stop();

        $js->publish("{$subject}.x", new Message('m4'));
        $js->publish("{$subject}.x", new Message('m5'));

        $subscription = $consumer->consume($handler);
        self::waitUntil(static fn(): bool => \count($received) === 5);
        $subscription->stop();

        self::assertSame([1, 2, 3, 4, 5], $received->getArrayCopy());

        $stream->delete();
    }

    public function testStartsFromTheGivenSequence(): void
    {
        $js = $this->client()->jetStream();
        [$stream, $subject] = self::createStream($js);

        for ($i = 1; $i <= 5; ++$i) {
            $js->publish("{$subject}.x", new Message("m{$i}"));
        }

        $iterator = $stream
            ->orderedConsumer(new OrderedConsumerConfig(
                deliverPolicy: DeliverPolicy::ByStartSequence,
                startSeq: 3,
            ))
            ->consumeIterator();

        $received = [];
        foreach ($iterator as $delivery) {
            $received[] = (string) $delivery->message->payload;

            if (\count($received) === 3) {
                $iterator->stop();
            }
        }

        self::assertSame(['m3', 'm4', 'm5'], $received);

        $stream->delete();
    }

    public function testFiltersSubjects(): void
    {
        $js = $this->client()->jetStream();
        [$stream, $subject] = self::createStream($js);

        foreach (['a', 'b', 'c', 'a', 'b'] as $i => $token) {
            $js->publish("{$subject}.{$token}", new Message("{$token}{$i}"));
        }

        $iterator = $stream
            ->orderedConsumer(new OrderedConsumerConfig(filterSubjects: ["{$subject}.a", "{$subject}.c"]))
            ->consumeIterator();

        $received = [];
        foreach ($iterator as $delivery) {
            $received[] = (string) $delivery->message->payload;

            if (\count($received) === 3) {
                $iterator->stop();
            }
        }

        self::assertSame(['a0', 'c2', 'a3'], $received);

        $stream->delete();
    }

    public function testRecreatesTheConsumerAfterDroppedMessages(): void
    {
        $nc = $this->client();
        $js = $nc->jetStream();
        [$stream, $subject] = self::createStream($js);

        $count = 50;
        for ($i = 1; $i <= $count; ++$i) {
            $js->publish("{$subject}.x", new Message("m{$i}"));
        }

        /** @var \ArrayObject<int, int> $received */
        $received = new \ArrayObject();
        /** @var \ArrayObject<string, true> $consumers */
        $consumers = new \ArrayObject();

        $subscription = $stream
            ->orderedConsumer(new OrderedConsumerConfig(
                // A dropped tail is revealed by the next heartbeat only.
                heartbeat: TimeSpan::fromMilliseconds(500),
                // Everything behind the slow first message is dropped but ten; a replacing consumer may drop again.
                pendingLimits: new PendingLimits(messages: 10, bytes: null),
            ))
            ->consume(static function (JetStreamDelivery $delivery) use ($received, $consumers): void {
                if ($received->count() === 0) {
                    delay(0.2);
                }

                $received[] = $delivery->metadata->streamSequence ?? 0;
                $consumers[$delivery->metadata->consumer ?? ''] = true;
            });

        self::waitUntil(static fn(): bool => \count($received) === $count, timeout: 20);
        $subscription->stop();

        self::assertSame(range(1, $count), $received->getArrayCopy());
        self::assertGreaterThan(1, \count($consumers), 'The consumer must have been recreated.');

        $stream->delete();
    }

    public function testHeartbeatRevealsTheDroppedLastMessage(): void
    {
        $js = $this->client()->jetStream();
        [$stream, $subject] = self::createStream($js);

        for ($i = 1; $i <= 3; ++$i) {
            $js->publish("{$subject}.x", new Message("m{$i}"));
        }

        /** @var \ArrayObject<int, string> $received */
        $received = new \ArrayObject();

        $subscription = $stream
            ->orderedConsumer(new OrderedConsumerConfig(
                heartbeat: TimeSpan::fromMilliseconds(500),
                // The first message is in the handler, the second waits, the third is dropped: no message follows
                // it to reveal the gap, only the heartbeat naming the third one as delivered.
                pendingLimits: new PendingLimits(messages: 1, bytes: null),
            ))
            ->consume(static function (JetStreamDelivery $delivery) use ($received): void {
                if ($received->count() === 0) {
                    delay(0.2);
                }

                $received[] = (string) $delivery->message->payload;
            });

        self::waitUntil(static fn(): bool => \count($received) === 3);
        $subscription->stop();

        self::assertSame(['m1', 'm2', 'm3'], $received->getArrayCopy());

        $stream->delete();
    }

    public function testRecreatesTheDeletedConsumer(): void
    {
        $js = $this->client()->jetStream();
        [$stream, $subject] = self::createStream($js);

        $js->publish("{$subject}.x", new Message('before'));

        /** @var \ArrayObject<int, JetStreamDelivery> $received */
        $received = new \ArrayObject();

        $subscription = $stream
            ->orderedConsumer(new OrderedConsumerConfig(heartbeat: TimeSpan::fromMilliseconds(500)))
            ->consume(static function (JetStreamDelivery $delivery) use ($received): void {
                $received[] = $delivery;
            });

        self::waitUntil(static fn(): bool => \count($received) === 1);

        $deleted = $received[0]->metadata->consumer ?? '';
        self::assertNotSame('', $deleted);
        $stream->deleteConsumer($deleted);

        $js->publish("{$subject}.x", new Message('after'));

        // The heartbeats stop with the consumer: two missed ones recreate it after the last message.
        self::waitUntil(static fn(): bool => \count($received) === 2);
        $subscription->stop();

        $after = $received[1] ?? self::fail('No delivery after the deletion.');
        self::assertSame('after', $after->message->payload);
        self::assertSame(2, $after->metadata?->streamSequence);
        self::assertNotSame($deleted, $after->metadata->consumer);

        $stream->delete();
    }

    public function testRecreatesTheConsumerAfterReconnect(): void
    {
        $proxy = PausableProxy::start($this->natsAddress());
        $config = $this->config();
        $nc = new Client(new Config(
            urls: [$proxy->address],
            user: $config->user,
            password: $config->password,
            reconnectWait: 0.1,
        ));
        $publisher = $this->client()->jetStream();

        /** @var DeferredFuture<void> $reconnected */
        $reconnected = new DeferredFuture();
        $nc->onReconnect(static function () use ($reconnected): void {
            if (!$reconnected->isComplete()) {
                $reconnected->complete();
            }
        });

        [$stream, $subject] = self::createStream($publisher);

        /** @var \ArrayObject<int, JetStreamDelivery> $received */
        $received = new \ArrayObject();

        try {
            $subscription = $nc
                ->jetStream()
                ->orderedConsumer($stream->name)
                ->consume(static function (JetStreamDelivery $delivery) use ($received): void {
                    $received[] = $delivery;
                });

            $publisher->publish("{$subject}.x", new Message('before'));
            self::waitUntil(static fn(): bool => \count($received) === 1);

            $proxy->disconnect();
            $reconnected->getFuture()->await(new TimeoutCancellation(5));

            $publisher->publish("{$subject}.x", new Message('after'));
            self::waitUntil(static fn(): bool => \count($received) === 2);
            $subscription->stop();

            self::assertSame(['before', 'after'], array_map(
                static fn(JetStreamDelivery $delivery): ?string => $delivery->message->payload,
                $received->getArrayCopy(),
            ));
            self::assertNotSame(($received[0] ?? null)?->metadata?->consumer, ($received[1] ?? null)?->metadata?->consumer);

            $stream->delete();
        } finally {
            $nc->stop();
            $proxy->kill();
        }
    }

    public function testReplacedOnceWithoutEcho(): void
    {
        $config = $this->config();
        // The client does not receive what it publishes itself: nothing may rely on it.
        $nc = $this->clientWithConfig(new Config(
            urls: $config->urls,
            user: $config->user,
            password: $config->password,
            noEcho: true,
        ));
        $js = $nc->jetStream();
        $publisher = $this->client()->jetStream();
        [$stream, $subject] = self::createStream($publisher);

        $publisher->publish("{$subject}.x", new Message('before'));

        /** @var \ArrayObject<int, string> $received */
        $received = new \ArrayObject();
        $subscription = $js
            ->orderedConsumer($stream->name, new OrderedConsumerConfig(heartbeat: TimeSpan::fromMilliseconds(500)))
            ->consume(static function (JetStreamDelivery $delivery) use ($received): void {
                $received[] = (string) $delivery->message->payload;
            });

        self::waitUntil(static fn(): bool => \count($received) === 1);

        $deleted = array_values([...$js->consumerNames($stream->name)]);
        self::assertCount(1, $deleted);
        $stream->deleteConsumer($deleted[0]);

        // The stream is read to the end: the replacing consumer has nothing to deliver, only heartbeats.
        self::waitUntil(static fn(): bool => \count([...$js->consumerNames($stream->name)]) === 1);
        $replacing = [...$js->consumerNames($stream->name)];
        self::assertNotSame($deleted, $replacing);

        delay(3);
        self::assertSame($replacing, [...$js->consumerNames($stream->name)]);

        $publisher->publish("{$subject}.x", new Message('after'));
        self::waitUntil(static fn(): bool => \count($received) === 2);
        $subscription->stop();

        self::assertSame(['before', 'after'], $received->getArrayCopy());

        $stream->delete();
    }

    public function testDeletedStreamFailsTheSubscription(): void
    {
        $js = $this->client()->jetStream();
        [$stream] = self::createStream($js);

        $subscription = $stream
            ->orderedConsumer(new OrderedConsumerConfig(heartbeat: TimeSpan::fromMilliseconds(500)))
            ->consume(static function (): void {});

        $stream->delete();

        // The heartbeats stop with the stream, and a consumer cannot be created anymore: no endless retries.
        self::expectException(StreamNotFound::class);
        $subscription->awaitCompletion(new TimeoutCancellation(5));
    }

    public function testOneConsumeAtATime(): void
    {
        $js = $this->client()->jetStream();
        [$stream] = self::createStream($js);

        $consumer = $stream->orderedConsumer();
        $consumer->consume(static function (): void {});

        try {
            self::expectException(ConsumerAlreadyConsuming::class);
            $consumer->consume(static function (): void {});
        } finally {
            $consumer->stop();
            $stream->delete();
        }
    }

    public function testStreamNotFound(): void
    {
        $nc = $this->client();

        $js = $nc->jetStream();
        $consumer = $js->orderedConsumer(generateUniqueId(10));

        $subscribers = new \ReflectionProperty(Client::class, 'subscribers');

        try {
            // The inbox of the requests to the JetStream API is subscribed once and kept by the client.
            $js->accountInfo();
            $before = array_keys((array) $subscribers->getValue($nc));

            for ($i = 0; $i < 3; ++$i) {
                try {
                    $consumer->consume(static function (): void {});
                    self::fail('The stream is expected not to be found.');
                } catch (StreamNotFound) {
                }
            }

            // Neither the subscription nor the deliver subjects of the consumers that were never created are kept.
            self::assertSame($before, array_keys((array) $subscribers->getValue($nc)));
        } finally {
            // The client stops without waiting for anything left behind.
            $nc->stop(new TimeoutCancellation(1));
        }
    }

    /**
     * @return array{Stream, non-empty-string}
     */
    private static function createStream(JetStream $js): array
    {
        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
        ));

        return [$stream, $subject];
    }

    /**
     * @param \Closure(): bool $condition
     */
    private static function waitUntil(\Closure $condition, float $timeout = 5): void
    {
        $deadline = microtime(true) + $timeout;

        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail('The condition was not met in time.');
            }

            delay(0.01);
        }
    }
}
