<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Internal\Hooks\ConcurrentProvider;
use Thesis\Nats\Internal\Hooks\PingReceived;
use Thesis\Nats\Internal\Protocol\Ping;
use Thesis\Nats\Internal\Protocol\Pong;
use function Amp\delay;

#[CoversClass(PingPongHandler::class)]
final class PingPongHandlerTest extends TestCase
{
    public function testPingPong(): void
    {
        $provider = new ConcurrentProvider();

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('hooks')
            ->willReturn($provider);

        $connection
            ->expects(self::exactly(10))
            ->method('execute')
            ->with(Pong::Frame);

        $handler = new PingPongHandler($connection);
        $handler->startup(10000, 5);

        for ($i = 0; $i < 10; ++$i) {
            $provider->dispatch(PingReceived::Event);
        }

        delay(0.1);
    }

    public function testMaxPingExhausted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(5))
            ->method('execute')
            ->with(Ping::Frame);

        $connection
            ->expects(self::once())
            ->method('close');

        $handler = new PingPongHandler($connection);
        $handler->startup(1, 5);
        delay(0.10);
    }
}
