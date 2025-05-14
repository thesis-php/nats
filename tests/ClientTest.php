<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\RequestHasNoResponders;

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

    public function testRequestReply(): void
    {
        $client = $this->client();

        $client->subscribe('events.*', static function (Delivery $delivery): void {
            $delivery->reply(new Message('ok'));
        });

        self::assertEquals('ok', $client->request('events.happens', new Message('Are you ok?'))->message->payload);

        $client->disconnect();
    }

    public function testUnsubscribe(): void
    {
        $client = $this->client();

        $client->unsubscribe($client->subscribe('events.*', static fn() => null));

        self::expectException(RequestHasNoResponders::class);
        $client->request('events.happens', new Message('Are you ok?'));
    }
}
