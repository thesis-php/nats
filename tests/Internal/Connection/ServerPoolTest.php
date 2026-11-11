<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerPool::class)]
#[CoversClass(Server::class)]
final class ServerPoolTest extends TestCase
{
    public function testNextRotatesServers(): void
    {
        $pool = new ServerPool(['a:4222', 'b:4222', 'c:4222'], randomize: false, maxReconnects: null);

        self::assertSame('b:4222', $pool->next()?->url);
        self::assertSame('c:4222', $pool->next()?->url);
        self::assertSame('a:4222', $pool->next()?->url);
        self::assertSame(['a:4222', 'b:4222', 'c:4222'], $pool->urls());
    }

    public function testServerIsDroppedOnceItUsesUpItsReconnects(): void
    {
        $pool = new ServerPool(['a:4222', 'b:4222'], randomize: false, maxReconnects: 1);

        $b = $pool->next();
        self::assertSame('b:4222', $b?->url);
        $b->failed(new \RuntimeException());

        // "a" has never failed, "b" has used up its only reconnect.
        self::assertSame('a:4222', $pool->next()?->url);
        self::assertSame(['a:4222'], $pool->urls());

        ($pool->servers()[0] ?? self::fail('The pool is empty.'))->failed(new \RuntimeException());
        self::assertNull($pool->next());
    }

    public function testSuccessfulConnectResetsReconnects(): void
    {
        $server = new Server('a:4222', implicit: false);

        self::assertFalse($server->failed(new \RuntimeException()));
        self::assertTrue($server->failed(new \RuntimeException()));
        self::assertFalse($server->failed(new \LogicException()));

        $server->connected();
        self::assertSame(0, $server->reconnects);
        self::assertNull($server->lastError);
    }

    public function testDiscoverAddsAnnouncedAndDropsGoneImplicitServers(): void
    {
        $pool = new ServerPool(['a:4222'], randomize: false, maxReconnects: null);

        self::assertFalse($pool->discover([]));
        self::assertTrue($pool->discover(['a:4222', 'b:4222', 'c:4222']));
        self::assertSame(['a:4222', 'b:4222', 'c:4222'], $pool->urls());

        // "c" has left the cluster, the configured "a" stays even if not announced.
        self::assertFalse($pool->discover(['b:4222']));
        self::assertSame(['a:4222', 'b:4222'], $pool->urls());

        // "c" is back: it was seen before, so it is not a new server.
        self::assertFalse($pool->discover(['b:4222', 'c:4222']));
        self::assertSame(['a:4222', 'b:4222', 'c:4222'], $pool->urls());
    }

    public function testDiscoverKeepsCurrentImplicitServer(): void
    {
        $pool = new ServerPool(['a:4222'], randomize: false, maxReconnects: null);
        $pool->discover(['b:4222']);
        $pool->use($pool->servers()[1] ?? self::fail('The server was not discovered.'));

        $pool->discover(['c:4222']);

        self::assertSame(['b:4222', 'a:4222', 'c:4222'], $pool->urls());
    }

    public function testRandomizedPoolKeepsAllServers(): void
    {
        $urls = array_map(static fn(int $i): string => "h{$i}:4222", range(1, 20));
        $pool = new ServerPool($urls, randomize: true, maxReconnects: null);

        $shuffled = $pool->urls();
        sort($shuffled);
        $expected = $urls;
        sort($expected);

        self::assertSame($expected, $shuffled);
    }

    public function testServerAnnouncedByAddressIsVerifiedAgainstTheNameOfTheAnnouncingOne(): void
    {
        $pool = new ServerPool(['nats.example.com:4222'], randomize: false, maxReconnects: null);
        $pool->discover(['10.0.0.2:4222', '[fd00::3]:4222', 'nats-2.example.com:4222']);

        self::assertSame(
            ['nats.example.com', 'nats.example.com', 'nats.example.com', 'nats-2.example.com'],
            array_map(static fn(Server $server): string => $server->tlsName(), $pool->servers()),
        );
    }

    public function testServerAnnouncedByAddressIsVerifiedAgainstItsOwnAddressWithoutAName(): void
    {
        $pool = new ServerPool(['127.0.0.2:4222'], randomize: false, maxReconnects: null);
        $pool->discover(['127.0.0.1:4222']);

        self::assertSame(
            ['127.0.0.2', '127.0.0.1'],
            array_map(static fn(Server $server): string => $server->tlsName(), $pool->servers()),
        );
    }

    public function testInheritedNameIsPassedOnByTheServerKnownByAddress(): void
    {
        $pool = new ServerPool(['nats.example.com:4222'], randomize: false, maxReconnects: null);
        $pool->discover(['10.0.0.2:4222']);

        // The client moves to the server known by address, which announces another one.
        $pool->use($pool->servers()[1] ?? self::fail('The server is not discovered.'));
        $pool->discover(['10.0.0.3:4222']);

        self::assertSame(
            ['10.0.0.2:4222' => 'nats.example.com', 'nats.example.com:4222' => 'nats.example.com', '10.0.0.3:4222' => 'nats.example.com'],
            array_combine($pool->urls(), array_map(static fn(Server $server): string => $server->tlsName(), $pool->servers())),
        );
    }

    public function testServerHost(): void
    {
        self::assertSame('nats.example.com', new Server('nats.example.com:4222', implicit: false)->host());
        self::assertSame('::1', new Server('[::1]:4222', implicit: false)->host());
        self::assertTrue(new Server('[::1]:4222', implicit: false)->hasIpAddress());
        self::assertFalse(new Server('nats.example.com:4222', implicit: false)->hasIpAddress());
    }
}
