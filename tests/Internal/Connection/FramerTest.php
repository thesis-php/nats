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
use Thesis\Nats\Internal\Protocol\ServerInfo;
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

        self::assertNull($framer->readFrame());
        self::assertNull($framer->readFrame());
    }

    public function testStartupFrameLeavesWhatCameAlongUnparsed(): void
    {
        $socket = $this->createMock(Socket::class);
        $socket
            ->expects(self::exactly(2))
            ->method('read')
            ->willReturnOnConsecutiveCalls('INFO {"version":', "\"2.12.0\"}\r\nPONG\r\nPI");

        $framer = new Framer($socket);

        self::assertInstanceOf(ServerInfo::class, $framer->readStartupFrame());
        self::assertTrue($framer->hasUnread());
        self::assertSame(Pong::Frame, $framer->readFrame());
    }

    public function testStartupFrameAloneLeavesNothingUnread(): void
    {
        $socket = $this->createMock(Socket::class);
        $socket
            ->expects(self::once())
            ->method('read')
            ->willReturn("INFO {\"version\":\"2.12.0\"}\r\n");

        $framer = new Framer($socket);

        self::assertInstanceOf(ServerInfo::class, $framer->readStartupFrame());
        self::assertFalse($framer->hasUnread());
    }

    public function testReadsTheSocketOnlyForTheAskedFrame(): void
    {
        $socket = $this->createMock(Socket::class);
        $socket
            ->expects(self::once())
            ->method('read')
            ->willReturn("INFO {\"version\":\"2.12.0\"}\r\n");

        $framer = new Framer($socket);

        // Nothing reads the socket after the frame, so it can be upgraded to TLS.
        self::assertInstanceOf(ServerInfo::class, $framer->readFrame());
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
