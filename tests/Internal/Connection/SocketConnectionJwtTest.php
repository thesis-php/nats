<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Config;
use Thesis\Nats\Internal\Nkey\KeyPair;
use Thesis\Nats\Internal\Protocol\Connect;
use function Thesis\Package\version;

#[CoversClass(Connect::class)]
#[CoversClass(KeyPair::class)]
final class SocketConnectionJwtTest extends TestCase
{
    private const string JWT = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.';
    private const string SEED = 'SUACSSL3UAHUDXKFSNVUZRF5UHPMWZ6BFDTJ7M6USDXIEDNPPQYYYCU3VY';
    private const string PUBLIC_KEY = 'UDXU4RCSJNZOIQHZNWXHXORDPRTGNJAHAHFRGZNEEJCPQTT2M7NLCNF4';

    public function testConnectProtocolCreationWithJwtOnly(): void
    {
        $config = new Config(
            jwt: self::JWT,
        );

        $encoded = self::connect($config)->encode();

        self::assertStringContainsString('"jwt":"' . self::JWT . '"', $encoded);
        self::assertStringNotContainsString('"nkey":', $encoded);
        self::assertStringNotContainsString('"sig":', $encoded);
    }

    #[RequiresPhpExtension('sodium')]
    public function testConnectProtocolCreationWithNkeyOnly(): void
    {
        $config = new Config(
            nkey: self::SEED,
        );

        $encoded = self::connect($config, 'test-nonce')->encode();

        // The public key is sent instead of the seed.
        self::assertStringContainsString('"nkey":"' . self::PUBLIC_KEY . '"', $encoded);
        self::assertStringNotContainsString(self::SEED, $encoded);
        self::assertStringContainsString('"sig":"' . KeyPair::fromSeed(self::SEED)->sign('test-nonce') . '"', $encoded);
        self::assertStringNotContainsString('"jwt":', $encoded);
    }

    #[RequiresPhpExtension('sodium')]
    public function testConnectProtocolCreationWithNkeyWithoutNonce(): void
    {
        $config = new Config(
            nkey: self::SEED,
        );

        $encoded = self::connect($config)->encode();

        self::assertStringContainsString('"nkey":"' . self::PUBLIC_KEY . '"', $encoded);
        self::assertStringNotContainsString('"sig":', $encoded);
    }

    #[RequiresPhpExtension('sodium')]
    public function testConnectProtocolCreationWithBothJwtAndNkey(): void
    {
        $config = new Config(
            jwt: self::JWT,
            nkey: self::SEED,
        );

        $encoded = self::connect($config, 'test-nonce')->encode();

        self::assertStringContainsString('"jwt":"' . self::JWT . '"', $encoded);
        self::assertStringContainsString('"nkey":"' . self::PUBLIC_KEY . '"', $encoded);
        self::assertStringNotContainsString(self::SEED, $encoded);
        self::assertStringContainsString('"sig":"' . KeyPair::fromSeed(self::SEED)->sign('test-nonce') . '"', $encoded);
    }

    public function testConnectProtocolCreationWithoutJwtAndNkey(): void
    {
        $config = new Config(
            user: 'admin',
            password: 'secret',
        );

        $encoded = self::connect($config)->encode();

        self::assertStringContainsString('"user":"admin"', $encoded);
        self::assertStringContainsString('"pass":"secret"', $encoded);
        self::assertStringNotContainsString('"jwt":', $encoded);
        self::assertStringNotContainsString('"nkey":', $encoded);
        self::assertStringNotContainsString('"sig":', $encoded);
    }

    #[RequiresPhpExtension('sodium')]
    public function testJwtAndNkeyAreSentAlongWithUserPassword(): void
    {
        $config = new Config(
            user: 'olduser',
            password: 'oldpass',
            jwt: self::JWT,
            nkey: self::SEED,
        );

        $encoded = self::connect($config, 'test-nonce')->encode();

        self::assertStringContainsString('"jwt":"' . self::JWT . '"', $encoded);
        self::assertStringContainsString('"nkey":"' . self::PUBLIC_KEY . '"', $encoded);
        self::assertStringContainsString('"sig":"', $encoded);
        self::assertStringContainsString('"user":"olduser"', $encoded);
        self::assertStringContainsString('"pass":"oldpass"', $encoded);
    }

    /**
     * Builds the CONNECT frame the same way SocketConnection::startup() does it.
     *
     * @param ?non-empty-string $nonce
     */
    private static function connect(Config $config, ?string $nonce = null): Connect
    {
        $keypair = $config->nkey !== null ? KeyPair::fromSeed($config->nkey) : null;

        return new Connect(
            verbose: $config->verbose,
            pedantic: $config->pedantic,
            tlsRequired: false,
            name: 'thesis/nats',
            version: version('thesis/nats'),
            user: $config->user,
            pass: $config->password,
            sig: $keypair !== null && $nonce !== null ? $keypair->sign($nonce) : null,
            jwt: $config->jwt,
            nkey: $keypair?->publicKey,
        );
    }
}
