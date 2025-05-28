<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Internal\Id;

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

            $deliveries->complete();
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

        $client->unsubscribe($client->subscribe("{$id}.*", static fn() => null));

        self::expectException(RequestHasNoResponders::class);
        $client->request("{$id}.happens", new Message('Are you ok?'));
    }
}
