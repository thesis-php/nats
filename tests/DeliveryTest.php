<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\Future\await;

#[CoversClass(Delivery::class)]
final class DeliveryTest extends TestCase
{
    public function testReplyNotRequest(): void
    {
        $delivery = new Delivery(static fn() => null, 'test');

        self::expectExceptionObject(new \LogicException('Message is not a request.'));
        $delivery->reply(new Message());
    }

    public function testReplyCall(): void
    {
        /** @var DeferredFuture<non-empty-string> $deferred */
        $deferred = new DeferredFuture();

        $delivery = new Delivery($deferred->complete(...), 'test', replyTo: 'test');

        $delivery->reply(new Message());
        self::assertSame('test', $deferred->getFuture()->await());
    }

    public function testReplyMultiple(): void
    {
        $delivery = new Delivery(static fn() => null, 'test', replyTo: 'test');

        $future1 = async($delivery->reply(...), new Message());
        $future2 = async($delivery->reply(...), new Message());

        self::expectExceptionObject(new \LogicException('Message is already replied.'));
        await([$future1, $future2]);
    }

    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $reply
     * @param positive-int $size
     */
    #[TestWith([
        1,
        'x',
    ])]
    #[TestWith([
        2,
        'x',
        'y',
    ])]
    #[TestWith([
        3,
        'x',
        'y',
        new Message('z'),
    ])]
    #[TestWith([
        15,
        'x',
        'y',
        new Message('z', new Headers(['X-Auth' => ['secret']])),
    ])]
    #[TestWith([
        17,
        'x',
        'y',
        new Message('z', new Headers(['X-Auth' => ['user', 'pass']])),
    ])]
    public function testDeliverySize(int $size, string $subject, ?string $reply = null, Message $message = new Message()): void
    {
        $delivery = new Delivery(
            static function (): void {},
            $subject,
            $reply,
            $message,
        );

        self::assertSame($size, $delivery->size());
    }
}
