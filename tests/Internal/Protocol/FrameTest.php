<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(Connect::class)]
final class FrameTest extends TestCase
{
    /**
     * @param non-empty-string $encoded
     */
    #[TestWith([
        Ok::Frame,
        "+OK\r\n",
    ])]
    #[TestWith([
        new Err('Authorization Violation'),
        "-ERR 'Authorization Violation'\r\n",
    ])]
    #[TestWith([
        Ping::Frame,
        "PING\r\n",
    ])]
    #[TestWith([
        Pong::Frame,
        "PONG\r\n",
    ])]
    #[TestWith([
        new ServerInfo(
            serverId: 'NCNITNSOJEGWKBAVPXS5M43GLPANADGR6YOMDAYSTVZDHDR6DLD64ZDA',
            serverName: 'nats-1',
            version: '2.11.1',
            go: 'go1.24.1',
            host: '0.0.0.0',
            port: 4222,
            headers: true,
            maxPayload: 1048576,
            proto: 1,
        ),
        'INFO {"server_id":"NCNITNSOJEGWKBAVPXS5M43GLPANADGR6YOMDAYSTVZDHDR6DLD64ZDA","server_name":"nats-1","version":"2.11.1","go":"go1.24.1","host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576,"proto":1}' . "\r\n",
    ])]
    #[TestWith([
        new Connect(
            verbose: false,
            pedantic: true,
            tlsRequired: false,
            name: 'thesis/nats',
            version: '0.1.x-dev',
        ),
        'CONNECT {"verbose":false,"pedantic":true,"tls_required":false,"name":"thesis\/nats","lang":"php","version":"0.1.x-dev","headers":true}' . "\r\n",
    ])]
    #[TestWith([
        new Sub('events.*', '1'),
        "SUB events.* 1\r\n",
    ])]
    #[TestWith([
        new Sub('events.*', '1', 'local'),
        "SUB events.* 1 local\r\n",
    ])]
    #[TestWith([
        new Pub('events.success'),
        "PUB events.success 0\r\n\r\n",
    ])]
    #[TestWith([
        new Pub('events.success', 'local'),
        "PUB events.success local 0\r\n\r\n",
    ])]
    #[TestWith([
        new Pub('events.success', 'local', new Message('abz')),
        "PUB events.success local 3\r\nabz\r\n",
    ])]
    #[TestWith([
        new HPub('events.success'),
        "HPUB events.success 12 12\r\nNATS/1.0\r\n\r\n\r\n",
    ])]
    #[TestWith([
        new HPub('events.success', 'local'),
        "HPUB events.success local 12 12\r\nNATS/1.0\r\n\r\n\r\n",
    ])]
    #[TestWith([
        new HPub('events.success', 'local', new Message('abz', new Headers())),
        "HPUB events.success local 12 15\r\nNATS/1.0\r\n\r\nabz\r\n",
    ])]
    #[TestWith([
        new HPub('events.success', 'local', new Message('abz', new Headers(['Bar' => ['Baz']]))),
        "HPUB events.success local 22 25\r\nNATS/1.0\r\nBar: Baz\r\n\r\nabz\r\n",
    ])]
    #[TestWith([
        new Msg('events.success', '1'),
        "MSG events.success 1 0\r\n\r\n",
    ])]
    #[TestWith([
        new Msg('events.success', '1', 'local'),
        "MSG events.success 1 local 0\r\n\r\n",
    ])]
    #[TestWith([
        new Msg('events.success', '1', 'local', new Message('abz')),
        "MSG events.success 1 local 3\r\nabz\r\n",
    ])]
    #[TestWith([
        new Msg('events.success', '1', 'local', new Message('abz')),
        "MSG events.success 1 local 3\r\nabz\r\n",
    ])]
    #[TestWith([
        new HMsg('events.success', '1'),
        "HMSG events.success 1 12 12\r\nNATS/1.0\r\n\r\n\r\n",
    ])]
    #[TestWith([
        new HMsg('events.success', '1', 'local'),
        "HMSG events.success 1 local 12 12\r\nNATS/1.0\r\n\r\n\r\n",
    ])]
    #[TestWith([
        new HMsg('events.success', '1', 'local', new Message('abz', new Headers())),
        "HMSG events.success 1 local 12 15\r\nNATS/1.0\r\n\r\nabz\r\n",
    ])]
    #[TestWith([
        new HMsg('events.success', '1', 'local', new Message('abz', new Headers(['Bar' => ['Baz']]))),
        "HMSG events.success 1 local 22 25\r\nNATS/1.0\r\nBar: Baz\r\n\r\nabz\r\n",
    ])]
    public function testEncode(Frame $frame, string $encoded): void
    {
        self::assertEquals($encoded, $frame->encode());
    }
}
