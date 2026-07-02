<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
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
    public function testFromURI(string $uri, Config $config): void
    {
        self::assertEquals($config, Config::fromURI($uri));
    }

    /**
     * @param non-empty-string $uri
     */
    #[TestWith(['tls://connect.ngs.global:4222', 'connect.ngs.global'])]
    #[TestWith(['nats+tls://example.com:4222', 'example.com'])]
    #[TestWith(['ssl://nats.internal:4222', 'nats.internal'])]
    public function testFromURIEnablesTlsFromScheme(string $uri, string $peerName): void
    {
        $config = Config::fromURI($uri);

        self::assertNotNull($config->tls);
        self::assertEquals(new \Amp\Socket\ClientTlsContext($peerName), $config->tls);
    }

    public function testFromURIWithoutTlsSchemeLeavesTlsNull(): void
    {
        self::assertNull(Config::fromURI('tcp://127.0.0.1:4222')->tls);
    }

    public function testFromArrayAcceptsTlsContext(): void
    {
        $tls = new \Amp\Socket\ClientTlsContext('nats.example.com');

        self::assertSame($tls, Config::fromArray(['tls' => $tls])->tls);
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
}
