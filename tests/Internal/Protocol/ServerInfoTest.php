<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerInfo::class)]
final class ServerInfoTest extends TestCase
{
    /**
     * @param non-empty-string $json
     */
    #[TestWith(
        [
            <<<'JSON'
                {
                   "server_id": "NCNITNSOJEGWKBAVPXS5M43GLPANADGR6YOMDAYSTVZDHDR6DLD64ZDA",
                   "server_name": "nats-1",
                   "version": "2.11.1",
                   "go": "go1.24.1",
                   "host": "0.0.0.0",
                   "port": 4222,
                   "headers": true,
                   "max_payload": 1048576,
                   "proto": 1,
                   "client_id": 47,
                   "auth_required": true,
                   "connect_urls": [
                        "172.24.0.2:4222",
                        "172.24.0.3:4222",
                        "172.24.0.4:4222"
                   ],
                   "git_commit": "d78523b"
                }
                JSON,
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
                clientId: 47,
                authRequired: true,
                connectUrls: [
                    '172.24.0.2:4222',
                    '172.24.0.3:4222',
                    '172.24.0.4:4222',
                ],
                gitCommit: 'd78523b',
            ),
        ],
    )]
    public function testFromJson(string $json, ServerInfo $info): void
    {
        self::assertEquals($info, ServerInfo::fromJson($json));
    }

    public function testInvalidJson(): void
    {
        self::expectException(\JsonException::class);
        ServerInfo::fromJson('{');
    }

    public function testInvalidInfo(): void
    {
        self::expectException(\UnexpectedValueException::class);
        self::expectExceptionMessage("'info' must be an non-empty associative array.");
        ServerInfo::fromJson('[{}]');
    }

    public function testEmptyInfo(): void
    {
        self::expectException(\UnexpectedValueException::class);
        self::expectExceptionMessage("'info' must be an non-empty associative array.");
        ServerInfo::fromJson('{}');
    }
}
