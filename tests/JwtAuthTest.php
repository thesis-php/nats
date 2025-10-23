<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Internal\Nkey\Signer;

#[CoversClass(Config::class)]
#[CoversClass(Signer::class)]
final class JwtAuthTest extends TestCase
{
    public function testConfigurationWithJwtOnly(): void
    {
        $jwt = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.';

        $config = new Config(
            jwt: $jwt,
        );

        self::assertEquals($jwt, $config->jwt);
        self::assertNull($config->nkey);
        self::assertNull($config->user);
        self::assertNull($config->password);
    }

    public function testConfigurationWithNkeyOnly(): void
    {
        $nkey = 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY';

        $config = new Config(
            nkey: $nkey,
        );

        self::assertEquals($nkey, $config->nkey);
        self::assertNull($config->jwt);
        self::assertNull($config->user);
        self::assertNull($config->password);
    }

    public function testConfigurationWithJwtAndNkey(): void
    {
        $jwt = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.';
        $nkey = 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY';

        $config = new Config(
            jwt: $jwt,
            nkey: $nkey,
        );

        self::assertEquals($jwt, $config->jwt);
        self::assertEquals($nkey, $config->nkey);
        self::assertNull($config->user);
        self::assertNull($config->password);
    }

    public function testBackwardCompatibilityWithoutJwtNkey(): void
    {
        $config = new Config(
            user: 'admin',
            password: 'secret',
        );

        self::assertEquals('admin', $config->user);
        self::assertEquals('secret', $config->password);
        self::assertNull($config->jwt);
        self::assertNull($config->nkey);
    }

    public function testMixedAuthenticationMethods(): void
    {
        $jwt = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.';
        $nkey = 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY';

        $config = new Config(
            user: 'admin',
            password: 'secret',
            jwt: $jwt,
            nkey: $nkey,
        );

        self::assertEquals('admin', $config->user);
        self::assertEquals('secret', $config->password);
        self::assertEquals($jwt, $config->jwt);
        self::assertEquals($nkey, $config->nkey);
    }

    public function testFromUriWithJwtPreservesOtherParameters(): void
    {
        $uri = 'tcp://admin:secret@127.0.0.1:4222?jwt=eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.&verbose=false&pedantic=true';

        $config = Config::fromURI($uri);

        self::assertEquals('admin', $config->user);
        self::assertEquals('secret', $config->password);
        self::assertEquals('eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.', $config->jwt);
        self::assertFalse($config->verbose);
        self::assertTrue($config->pedantic);
    }
}
