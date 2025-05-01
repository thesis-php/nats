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
    public function testFromURI(string $uri, Config $config): void
    {
        self::assertEquals($config, Config::fromURI($uri));
    }
}
