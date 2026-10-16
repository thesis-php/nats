<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Internal\Connection\Query;
use Thesis\Nats\Internal\Connection\Uri;

#[CoversClass(Config::class)]
#[CoversClass(Uri::class)]
#[CoversClass(Query::class)]
final class ConfigTest extends TestCase
{
    /**
     * @param non-empty-string $uri
     */
    #[TestWith(
        [
            'tcp://127.0.0.1:4222',
            new Config(),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222',
            new Config(
                user: 'admin',
                password: 'secret',
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222?verbose=false&pedantic=true',
            new Config(
                verbose: false,
                pedantic: true,
                user: 'admin',
                password: 'secret',
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222?verbose=false&pedantic=true&client_name=prod',
            new Config(
                verbose: false,
                pedantic: true,
                user: 'admin',
                password: 'secret',
                clientName: 'prod',
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222,127.0.0.1:4223?verbose=false&pedantic=true',
            new Config(
                urls: [
                    '127.0.0.1:4222',
                    '127.0.0.1:4223',
                ],
                verbose: false,
                pedantic: true,
                user: 'admin',
                password: 'secret',
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222,127.0.0.1:4223?verbose=false&pedantic=true&connection_timeout=5&tcp_nodelay=false',
            new Config(
                urls: [
                    '127.0.0.1:4222',
                    '127.0.0.1:4223',
                ],
                verbose: false,
                pedantic: true,
                connectionTimeout: 5,
                user: 'admin',
                password: 'secret',
                tcpNoDelay: false,
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222,127.0.0.1:4223?verbose=false&pedantic=true&connection_timeout=5&tcp_nodelay=false&no_responders=true',
            new Config(
                urls: [
                    '127.0.0.1:4222',
                    '127.0.0.1:4223',
                ],
                verbose: false,
                pedantic: true,
                connectionTimeout: 5,
                user: 'admin',
                password: 'secret',
                tcpNoDelay: false,
                noResponders: true,
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222,127.0.0.1:4223?verbose=false&pedantic=true&connection_timeout=5&tcp_nodelay=false&no_responders=true&ping=2000',
            new Config(
                urls: [
                    '127.0.0.1:4222',
                    '127.0.0.1:4223',
                ],
                verbose: false,
                pedantic: true,
                connectionTimeout: 5,
                user: 'admin',
                password: 'secret',
                tcpNoDelay: false,
                noResponders: true,
                ping: 2000,
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222,127.0.0.1:4223?verbose=false&pedantic=true&connection_timeout=5&tcp_nodelay=false&no_responders=true&ping=2000&max_pings=10',
            new Config(
                urls: [
                    '127.0.0.1:4222',
                    '127.0.0.1:4223',
                ],
                verbose: false,
                pedantic: true,
                connectionTimeout: 5,
                user: 'admin',
                password: 'secret',
                tcpNoDelay: false,
                noResponders: true,
                ping: 2000,
                maxPings: 10,
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222,127.0.0.1:4223?verbose=false&pedantic=true&connection_timeout=5&tcp_nodelay=false&no_responders=true&ping=2000&max_pings=10&jetstream_domain=local',
            new Config(
                urls: [
                    '127.0.0.1:4222',
                    '127.0.0.1:4223',
                ],
                verbose: false,
                pedantic: true,
                connectionTimeout: 5,
                user: 'admin',
                password: 'secret',
                tcpNoDelay: false,
                noResponders: true,
                ping: 2000,
                maxPings: 10,
                jetStreamDomain: 'local',
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://127.0.0.1:4222?jwt=eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.&nkey=SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY',
            new Config(
                jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
                nkey: 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY',
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://admin:secret@127.0.0.1:4222?jwt=eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.&nkey=SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY&verbose=false',
            new Config(
                verbose: false,
                user: 'admin',
                password: 'secret',
                jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
                nkey: 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY',
            ),
        ],
    )]
    #[TestWith(
        [
            'tcp://127.0.0.1:4222?request_timeout=2.5',
            new Config(requestTimeout: 2.5),
        ],
    )]
    #[TestWith(
        [
            'tcp://127.0.0.1:4222?request_timeout=-1',
            new Config(),
        ],
    )]
    #[TestWith(
        [
            'tcp://127.0.0.1:4222?connection_timeout=0.5',
            new Config(connectionTimeout: 0.5),
        ],
    )]
    #[TestWith(
        [
            'nats://user:p%40ss%3Aw%2Frd@127.0.0.1:4222',
            new Config(user: 'user', password: 'p@ss:w/rd'),
        ],
    )]
    #[TestWith(
        [
            'NATS://h1,h2:4223',
            new Config(urls: ['h1:4222', 'h2:4223']),
        ],
    )]
    #[TestWith(
        [
            'nats://admin:secret@h1:4222, nats://h2:4223,tcp://h3/?verbose=true',
            new Config(urls: ['h1:4222', 'h2:4223', 'h3:4222'], verbose: true, user: 'admin', password: 'secret'),
        ],
    )]
    #[TestWith(
        [
            'h1:4223',
            new Config(urls: ['h1:4223']),
        ],
    )]
    #[TestWith(
        [
            'nats://[::1]:4223,[fe80::1]',
            new Config(urls: ['[::1]:4223', '[fe80::1]:4222']),
        ],
    )]
    #[TestWith(
        [
            'nats://',
            new Config(),
        ],
    )]
    #[TestWith(
        [
            'nats://:4223',
            new Config(urls: ['127.0.0.1:4223']),
        ],
    )]
    #[TestWith(
        [
            'nats://127.0.0.1:4222?ping=-1&max_pings=3&jetstream_domain=hub',
            new Config(ping: null, maxPings: 3, jetStreamDomain: 'hub'),
        ],
    )]
    #[TestWith(
        [
            'nats://127.0.0.1:4222?ping=0&max_pings=0&connection_timeout=INF&request_timeout=NAN&client_name=',
            new Config(),
        ],
    )]
    #[TestWith(
        [
            'nats://127.0.0.1:4222?ping=1.5&max_pings=x&verbose=maybe&tcp_nodelay=maybe&jwt[]=x',
            new Config(),
        ],
    )]
    #[TestWith(
        [
            'nats://127.0.0.1:4222?verbose=1&pedantic=yes&tcp_nodelay=off&no_responders=on',
            new Config(verbose: true, pedantic: true, tcpNoDelay: false, noResponders: true),
        ],
    )]
    #[TestWith(
        [
            'nats://user:pass@h1:4222,nats://user:pass@h2:4223',
            new Config(urls: ['h1:4222', 'h2:4223'], user: 'user', password: 'pass'),
        ],
    )]
    #[TestWith(
        [
            'nats://h1,nats://user:p%2Cass@h2',
            new Config(urls: ['h1:4222', 'h2:4222'], user: 'user', password: 'p,ass'),
        ],
    )]
    public function testFromURI(string $uri, Config $config): void
    {
        self::assertEquals($config, Config::fromURI($uri));
    }

    /**
     * @param non-empty-string $uri
     */
    #[TestWith(['tls://127.0.0.1:4222'])]
    #[TestWith(['ws://127.0.0.1:4222'])]
    #[TestWith(['wss://127.0.0.1:4222'])]
    #[TestWith(['http://127.0.0.1:4222'])]
    #[TestWith(['nats://h1:4222,ws://h2:4222'])]
    #[TestWith(['nats://user:pass@h1:4222,tls://user:pass@h2:4222'])]
    #[TestWith(['nats://h1:4222,tls://user:pass@h2:4222'])]
    #[TestWith(['nats://user:pass@h1:4222,nats://user:other@h2:4222'])]
    #[TestWith(['nats://user:pass@h1:4222,nats://h2:4222@h3'])]
    #[TestWith(['nats://,h2:4222'])]
    #[TestWith(['nats://h1:4222,,h2:4222'])]
    #[TestWith(['nats://h1:4222,'])]
    #[TestWith(['nats://h1:port'])]
    #[TestWith(['nats://h1:0'])]
    #[TestWith(['nats://h1:65536'])]
    #[TestWith(['nats://h1:-1'])]
    #[TestWith(['nats://::1'])]
    #[TestWith(['nats://[::1'])]
    #[TestWith(['nats://[::1]4222'])]
    #[TestWith(['nats://h1:4222/path'])]
    public function testFromURIRejectsInvalidUri(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::fromURI($uri);
    }

    #[TestWith([0.0])]
    #[TestWith([-1.0])]
    #[TestWith([INF])]
    #[TestWith([NAN])]
    public function testConnectionTimeoutMustBePositive(float $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(connectionTimeout: $timeout);
    }

    public function testFromArrayWithJwtAndNkey(): void
    {
        $config = Config::fromArray([
            'jwt' => 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
            'nkey' => 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY',
            'verbose' => false,
            'pedantic' => true,
        ]);

        $expected = new Config(
            verbose: false,
            pedantic: true,
            jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
            nkey: 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY',
        );

        self::assertEquals($expected, $config);
    }

    public function testFromArrayWithoutJwtAndNkey(): void
    {
        $config = Config::fromArray([
            'verbose' => false,
            'pedantic' => true,
        ]);

        $expected = new Config(
            verbose: false,
            pedantic: true,
        );

        self::assertEquals($expected, $config);
    }

    public function testFromArrayWithRequestTimeout(): void
    {
        self::assertEquals(new Config(requestTimeout: 0.5), Config::fromArray(['request_timeout' => 0.5]));
    }

    #[TestWith([0.0])]
    #[TestWith([-1.0])]
    #[TestWith([INF])]
    #[TestWith([NAN])]
    public function testRequestTimeoutMustBePositive(float $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(requestTimeout: $timeout);
    }
}
