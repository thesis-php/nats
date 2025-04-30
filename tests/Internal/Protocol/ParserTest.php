<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use Amp\Pipeline\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(Parser::class)]
final class ParserTest extends TestCase
{
    /**
     * @param non-empty-list<non-empty-string>|non-empty-string $lines
     * @param non-empty-list<Frame>|Frame $frames
     */
    #[TestWith(
        [
            "+OK\r\n",
            Ok::Frame,
        ],
    )]
    #[TestWith(
        [
            "-ERR 'Slow consumer'\r\n",
            new Err('Slow consumer'),
        ],
    )]
    #[TestWith(
        [
            "PING\r\n",
            Ping::Frame,
        ],
    )]
    #[TestWith(
        [
            "PONG\r\n",
            Pong::Frame,
        ],
    )]
    #[TestWith(
        [
            'INFO {"server_id":"NCNITNSOJEGWKBAVPXS5M43GLPANADGR6YOMDAYSTVZDHDR6DLD64ZDA","server_name":"nats-1","version":"2.11.1","go":"go1.24.1","host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576,"proto":1}' . "\r\n",
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
        ],
    )]
    #[TestWith(
        [
            "MSG FOO.BAR 9 12\r\nHello, world\r\n",
            new Msg(
                subject: 'FOO.BAR',
                sid: '9',
                message: new Message('Hello, world'),
            ),
        ],
    )]
    #[TestWith(
        [
            "MSG FOO.BAR 9 0\r\n\r\n",
            new Msg(
                subject: 'FOO.BAR',
                sid: '9',
            ),
        ],
    )]
    #[TestWith(
        [
            "MSG FOO.BAR 9 BAZ.69 12\r\nHello, world\r\n",
            new Msg(
                subject: 'FOO.BAR',
                sid: '9',
                replyTo: 'BAZ.69',
                message: new Message('Hello, world'),
            ),
        ],
    )]
    #[TestWith(
        [
            [
                "MSG FOO.BAR 9 BAZ.69 12\r\n",
                "Hello, world\r\n",
            ],
            new Msg(
                subject: 'FOO.BAR',
                sid: '9',
                replyTo: 'BAZ.69',
                message: new Message('Hello, world'),
            ),
        ],
    )]
    #[TestWith(
        [
            "MSG FOO.BAR 9 BAZ.69 12\r\nHello, world\r\n",
            new Msg(
                subject: 'FOO.BAR',
                sid: '9',
                replyTo: 'BAZ.69',
                message: new Message('Hello, world'),
            ),
        ],
    )]
    #[TestWith(
        [
            "HMSG FOO.BAR 9 BAZ.69 34 45\r\nNATS/1.0\r\nFoodGroup: vegetable\r\n\r\nHello World\r\n",
            new Msg(
                subject: 'FOO.BAR',
                sid: '9',
                replyTo: 'BAZ.69',
                message: new Message(
                    payload: 'Hello World',
                    headers: new Headers([
                        'FoodGroup' => ['vegetable'],
                    ]),
                ),
            ),
        ],
    )]
    #[TestWith(
        [
            [
                "HMSG FOO.BAR 9 BAZ.69 34 45\r\n",
                "NATS/1.0\r\n",
                "FoodGroup: vegetable\r\n\r\n",
                "Hello World\r\n",
            ],
            new Msg(
                subject: 'FOO.BAR',
                sid: '9',
                replyTo: 'BAZ.69',
                message: new Message(
                    payload: 'Hello World',
                    headers: new Headers([
                        'FoodGroup' => ['vegetable'],
                    ]),
                ),
            ),
        ],
    )]
    #[TestWith(
        [
            [
                "HMSG FOO.BAR 9 BAZ.69 34 45\r\n",
                "NATS/1.0\r\n",
                "FoodGroup: vegetable\r\n\r\n",
                "Hello World\r\n",
                "MSG FOO.BAR 9 BAZ.69 12\r\nHello, world\r\n",
                "HMSG FOO.BAR 9 40 51\r\nNATS/1.0\r\nFoodGroup: vegetable\r\nX: Y\r\n\r\nHello World\r\n",
            ],
            [
                new Msg(
                    subject: 'FOO.BAR',
                    sid: '9',
                    replyTo: 'BAZ.69',
                    message: new Message(
                        payload: 'Hello World',
                        headers: new Headers([
                            'FoodGroup' => ['vegetable'],
                        ]),
                    ),
                ),
                new Msg(
                    subject: 'FOO.BAR',
                    sid: '9',
                    replyTo: 'BAZ.69',
                    message: new Message('Hello, world'),
                ),
                new Msg(
                    subject: 'FOO.BAR',
                    sid: '9',
                    message: new Message(
                        payload: 'Hello World',
                        headers: new Headers([
                            'FoodGroup' => ['vegetable'],
                            'X' => ['Y'],
                        ]),
                    ),
                ),
            ],
        ],
    )]
    public function testParsed(array|string $lines, array|Frame $frames): void
    {
        if (!\is_array($frames)) {
            $frames = [$frames];
        }

        if (!\is_array($lines)) {
            $lines = [$lines];
        }

        /** @var Queue<Frame> $queue */
        $queue = new Queue(\count($frames));
        $iterator = $queue->iterate();

        $parser = new Parser($queue->push(...));

        foreach ($lines as $line) {
            $parser->push($line);
        }

        $parser->cancel();
        $queue->complete();

        self::assertEquals($frames, [...$iterator]);
    }
}
