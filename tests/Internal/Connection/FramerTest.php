<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Socket\Socket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Internal\Protocol\Err;
use Thesis\Nats\Internal\Protocol\Frame;
use Thesis\Nats\Internal\Protocol\Message;
use Thesis\Nats\Internal\Protocol\Msg;
use Thesis\Nats\Internal\Protocol\Ok;
use Thesis\Nats\Internal\Protocol\Ping;
use Thesis\Nats\Internal\Protocol\Pong;
use Thesis\Nats\Internal\Protocol\Pub;
use Thesis\Nats\Internal\Protocol\Sub;

#[CoversClass(Framer::class)]
final class FramerTest extends TestCase
{
    /**
     * @param list<non-empty-string> $responses
     * @param list<Frame> $frames
     */
    #[TestWith([
        [
            "PING\r\n",
            "PONG\r\n",
            "+OK\r\n",
            "-ERR 'Authorization Violation'\r\n",
            "MSG events.success 1 3\r\nabz\r\n",
        ],
        [
            Ping::Frame,
            Pong::Frame,
            Ok::Frame,
            new Err('Authorization Violation'),
            new Msg('events.success', '1', message: new Message('abz')),
        ],
    ])]
    public function testReadFrame(array $responses, array $frames): void
    {
        $responses[] = null;
        $socket = $this->createMock(Socket::class);
        $socket
            ->expects(self::exactly(\count($responses)))
            ->method('read')
            ->willReturnOnConsecutiveCalls(...$responses);

        $socket
            ->expects(self::once())
            ->method('close');

        $framer = new Framer($socket);

        for ($i = 0; $i < \count($frames); ++$i) {
            self::assertEquals($frames[$i], $framer->readFrame());
        }
    }

    public function testWriteFrame(): void
    {
        $socket = $this->createMock(Socket::class);
        $socket
            ->expects(self::once())
            ->method('write')
            ->with("PONG\r\nSUB events.* 1\r\nPUB events.success 3\r\nabz\r\n");

        $framer = new Framer($socket);

        $framer->writeFrame([
            Pong::Frame,
            new Sub('events.*', '1'),
            new Pub('events.success', message: new Message('abz')),
        ]);
    }
}
