<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use Thesis\Nats\Exception\SlowConsumer;
use Thesis\Nats\Internal\Id;
use function Amp\delay;

#[CoversClass(Subscription::class)]
#[CoversClass(Client::class)]
final class SubscriptionTest extends NatsTestCase
{
    public function testSubscriptionExposesSubjectAndQueueGroup(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $plain = $client->subscribe("{$id}.*", static fn() => null);
        self::assertSame("{$id}.*", $plain->subject);
        self::assertNull($plain->queueGroup);

        $queued = $client->subscribe("{$id}.>", static fn() => null, queueGroup: 'workers');
        self::assertSame("{$id}.>", $queued->subject);
        self::assertSame('workers', $queued->queueGroup);
    }

    public function testSubscriptionCompletesAfterMaxMessages(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        /** @var \ArrayObject<int, ?string> $received */
        $received = new \ArrayObject();

        $subscription = $client->subscribe(
            $id,
            static function (Delivery $delivery) use ($received): void {
                $received[] = $delivery->message->payload;
            },
            max: 2,
        );

        foreach (['1', '2', '3', '4'] as $payload) {
            $client->publish($id, new Message($payload));
        }

        $subscription->awaitCompletion(new TimeoutCancellation(5));
        self::assertSame(['1', '2'], $received->getArrayCopy());
        self::assertSame(2, $subscription->stats()->delivered);

        // The server has removed the subscription, so nothing is received anymore.
        $client->publish($id, new Message('5'));
        delay(0.1);

        self::assertSame(['1', '2'], $received->getArrayCopy());
        self::assertSame([], self::subscribers($client));
    }

    public function testIteratorEndsAfterMaxMessages(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        $deliveries = $client->subscribeIterator($id, max: 3);

        foreach (['1', '2', '3', '4', '5'] as $payload) {
            $client->publish($id, new Message($payload));
        }

        $received = [];
        foreach ($deliveries as $delivery) {
            $received[] = $delivery->message->payload;
        }

        self::assertSame(['1', '2', '3'], $received);
        self::assertSame([], self::subscribers($client));
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function testSubscribeRejectsNonPositiveMax(int $max): void
    {
        $client = $this->client();

        try {
            /** @phpstan-ignore argument.type */
            $client->subscribe(Id\generateUniqueId(), static fn() => null, max: $max);
            self::fail('The max is invalid, the subscription must be refused.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], self::subscribers($client));
    }

    public function testStopBeforeMaxMessagesUnsubscribes(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        $subscription = $client->subscribe($id, static fn() => null, max: 10);

        $client->publish($id, new Message('1'));
        $subscription->stop();
        $subscription->awaitCompletion(new TimeoutCancellation(5));

        self::assertSame([], self::subscribers($client));
    }

    public function testSlowConsumerDropsMessagesBeyondPendingLimits(): void
    {
        $client = $this->client();

        /** @var \ArrayObject<int, \Throwable> $errors */
        $errors = new \ArrayObject();
        $client->onError(static function (\Throwable $e) use ($errors): void {
            $errors[] = $e;
        });

        $id = Id\generateUniqueId();

        /** @var DeferredFuture<void> $release */
        $release = new DeferredFuture();

        /** @var \ArrayObject<int, ?string> $received */
        $received = new \ArrayObject();

        $subscription = $client->subscribe(
            $id,
            static function (Delivery $delivery) use (
                $release,
                $received,
            ): void {
                $received[] = $delivery->message->payload;
                $release->getFuture()->await();
            },
            pendingLimits: new PendingLimits(messages: 2, bytes: null),
        );

        // The handler is busy with the first message, the rest pile up.
        $client->publish($id, new Message('1'));
        self::waitFor(static fn() => $subscription->stats()->delivered === 1);

        foreach (['2', '3', '4', '5'] as $payload) {
            $client->publish($id, new Message($payload));
        }

        self::waitFor(static fn() => $subscription->stats()->dropped === 2);

        $stats = $subscription->stats();
        self::assertSame(1, $stats->delivered);
        self::assertSame(2, $stats->pendingMessages);
        self::assertSame(2, $stats->pendingBytes);

        self::assertCount(1, $errors, 'A slow consumer is reported once per episode.');
        self::assertInstanceOf(SlowConsumer::class, $errors[0]);
        self::assertSame($subscription, $errors[0]->subscription);

        $release->complete();
        self::waitFor(static fn() => $subscription->stats()->pendingMessages === 0);

        self::assertSame(['1', '2', '3'], $received->getArrayCopy());
        self::assertEquals(new SubscriptionStats(pendingMessages: 0, pendingBytes: 0, delivered: 3, dropped: 2), $subscription->stats());

        // Caught up: the next overflow is a new episode.
        $client->publish($id, new Message('6'));
        self::waitFor(static fn() => $subscription->stats()->delivered === 4);
        self::assertCount(1, $errors);
    }

    public function testPendingBytesLimit(): void
    {
        $client = $this->client();
        $client->onError(static fn() => null);

        $id = Id\generateUniqueId();

        /** @var DeferredFuture<void> $release */
        $release = new DeferredFuture();

        $subscription = $client->subscribe(
            $id,
            static fn() => $release->getFuture()->await(),
            pendingLimits: new PendingLimits(messages: null, bytes: 10),
        );

        $client->publish($id, new Message('first'));
        self::waitFor(static fn() => $subscription->stats()->delivered === 1);

        foreach (['12345', '67890', 'x'] as $payload) {
            $client->publish($id, new Message($payload));
        }

        self::waitFor(static fn() => $subscription->stats()->dropped === 1);

        // 'first' is in the handler, '12345' and '67890' fill the 10 bytes, 'x' does not fit.
        self::assertSame(10, $subscription->stats()->pendingBytes);

        $release->complete();
    }

    public function testIteratorDropsMessagesBeyondPendingLimits(): void
    {
        $client = $this->client();

        /** @var DeferredFuture<\Throwable> $error */
        $error = new DeferredFuture();
        $client->onError(static function (\Throwable $e) use ($error): void {
            if (!$error->isComplete()) {
                $error->complete($e);
            }
        });

        $id = Id\generateUniqueId();

        // Nobody iterates: the buffer fills up, then the pending messages reach the limit.
        $deliveries = $client->subscribeIterator($id, bufferSize: 1, pendingLimits: new PendingLimits(messages: 1, bytes: null));

        foreach (['1', '2', '3', '4', '5'] as $payload) {
            $client->publish($id, new Message($payload));
        }

        $e = $error->getFuture()->await(new TimeoutCancellation(5));
        self::assertInstanceOf(SlowConsumer::class, $e);
        self::assertSame($id, $e->subscription?->subject);

        $deliveries->stop();
    }

    public function testStatsAreResetOnStop(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        /** @var DeferredFuture<void> $release */
        $release = new DeferredFuture();

        $subscription = $client->subscribe($id, static fn() => $release->getFuture()->await());

        $client->publish($id, new Message('1'));
        self::waitFor(static fn() => $subscription->stats()->delivered === 1);

        foreach (['2', '3'] as $payload) {
            $client->publish($id, new Message($payload));
        }

        self::waitFor(static fn() => $subscription->stats()->pendingMessages === 2);

        $release->complete();
        $subscription->stop();
        self::waitFor(static fn() => $subscription->stats()->pendingMessages === 0);

        self::assertSame(0, $subscription->stats()->pendingBytes);
    }

    /**
     * @param \Closure(): bool $condition
     */
    private static function waitFor(\Closure $condition, float $timeout = 5): void
    {
        $deadline = microtime(true) + $timeout;

        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail('The condition was not met in time.');
            }

            delay(0.01);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function subscribers(Client $client): array
    {
        /** @var array<array-key, mixed> */
        return (new \ReflectionProperty(Client::class, 'subscribers'))->getValue($client);
    }
}
