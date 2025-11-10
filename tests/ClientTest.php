<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Internal\Id;
use function Amp\delay;

#[CoversClass(Client::class)]
final class ClientTest extends NatsTestCase
{
    public function testPublishSubscribe(): void
    {
        $client = $this->client();

        /** @var DeferredFuture<Delivery> $deferred */
        $deferred = new DeferredFuture();

        $client->subscribe('events.*', $deferred->complete(...));

        $client->publish('events.happens', new Message('ok'));

        $delivery = $deferred->getFuture()->await();
        self::assertEquals('events.happens', $delivery->subject);
        self::assertEquals('ok', $delivery->message->payload);

        $client->disconnect();
    }

    public function testPublishSubscribeIterator(): void
    {
        $client = $this->client();

        $deliveries = $client->subscribeIterator('events.*');
        $client->publish('events.happens', new Message('ok'));

        foreach ($deliveries as $delivery) {
            self::assertEquals('events.happens', $delivery->subject);
            self::assertEquals('ok', $delivery->message->payload);

            $deliveries->stop();
        }
    }

    public function testRequestReply(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $client->subscribe("{$id}.*", static function (Delivery $delivery): void {
            $delivery->reply(new Message('ok'));
        });

        self::assertEquals('ok', $client->request("{$id}.happens", new Message('Are you ok?'))->message->payload);

        $client->disconnect();
    }

    public function testUnsubscribe(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $client->subscribe("{$id}.*", static fn() => null)->stop();

        self::expectException(RequestHasNoResponders::class);
        $client->request("{$id}.happens", new Message('Are you ok?'));
    }

    public function testStopSubscription(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $count = 0;

        $subscription = $client->subscribe("{$id}.*", static function (Delivery $delivery) use (&$count): void {
            if ($count === 0) {
                // Let the subscription accumulate messages in its local queue buffer.
                delay(0.1);
            }

            ++$count;
        });

        for ($i = 0; $i < 10; ++$i) {
            $client->publish("{$id}.{$i}", new Message("{$i}"));
        }

        delay(0.1);
        $subscription->stop();
        $subscription->suspend();

        self::assertSame(1, $count);
    }

    public function testDrainSubscription(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $count = 0;

        $subscription = $client->subscribe("{$id}.*", static function (Delivery $delivery) use (&$count): void {
            if ($count === 0) {
                // Let the subscription accumulate messages in its local queue buffer.
                delay(0.1);
            }

            ++$count;
        });

        for ($i = 0; $i < 10; ++$i) {
            $client->publish("{$id}.{$i}", new Message("{$i}"));
        }

        delay(0.1);
        $subscription->drain();
        $subscription->suspend();

        self::assertSame(10, $count);
    }

    public function testSuspendExceptionalSubscription(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $subscription = $client->subscribe("{$id}.*", static function (): void {
            throw new \RuntimeException('Exception in test.');
        });

        $client->publish("{$id}.x");

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Exception in test.');
        $subscription->suspend();
    }

    #[DoesNotPerformAssertions]
    public function testSuspendSubscriptionOnClientDisconnect(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();

        $subscription = $client->subscribe("{$id}.*", static function (): void {});
        $client->disconnect();
        $subscription->suspend();
    }
}
