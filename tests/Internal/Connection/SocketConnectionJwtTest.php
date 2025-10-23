<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Config;
use Thesis\Nats\Internal\Protocol\Connect;

#[CoversClass(Connect::class)]
#[CoversClass(Signer::class)]
final class SocketConnectionJwtTest extends TestCase
{
    public function testConnectProtocolCreationWithJwtOnly(): void
    {
        $config = new Config(
            jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
        );

        $connect = new Connect(
            verbose: $config->verbose,
            pedantic: $config->pedantic,
            tlsRequired: false,
            name: 'thesis/nats',
            version: $config->version,
            user: $config->user,
            pass: $config->password,
            sig: null,
            jwt: $config->jwt,
            nkey: null,
        );

        $encoded = $connect->encode();

        self::assertStringContainsString('"jwt":"eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0."', $encoded);
        self::assertStringNotContainsString('"nkey":', $encoded);
        self::assertStringNotContainsString('"sig":', $encoded);
    }

    #[RequiresPhpExtension('sodium')]
    public function testConnectProtocolCreationWithNkeyOnly(): void
    {
        $config = new Config(
            nkey: 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU',
        );

        $nonce = 'test-nonce';
        $nkey = $config->nkey;
        self::assertNotNull($nkey);
        $signer = new Signer();
        $signature = $signer->sign($nonce, $nkey);

        $connect = new Connect(
            verbose: $config->verbose,
            pedantic: $config->pedantic,
            tlsRequired: false,
            name: 'thesis/nats',
            version: $config->version,
            user: $config->user,
            pass: $config->password,
            sig: $signature,
            jwt: $config->jwt,
            nkey: 'test-nkey',
        );

        $encoded = $connect->encode();

        self::assertStringContainsString('"nkey":"test-nkey"', $encoded);
        self::assertStringContainsString('"sig":"', $encoded);
        self::assertStringContainsString($signature, str_replace('\/', '/', $encoded));
        self::assertStringNotContainsString('"jwt":', $encoded);
    }

    #[RequiresPhpExtension('sodium')]
    public function testConnectProtocolCreationWithBothJwtAndNkey(): void
    {
        $config = new Config(
            jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
            nkey: 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU',
        );

        $nonce = 'test-nonce';
        $nkey = $config->nkey;
        self::assertNotNull($nkey);
        $signer = new Signer();
        $signature = $signer->sign($nonce, $nkey);

        $connect = new Connect(
            verbose: $config->verbose,
            pedantic: $config->pedantic,
            tlsRequired: false,
            name: 'thesis/nats',
            version: $config->version,
            user: $config->user,
            pass: $config->password,
            sig: $signature,
            jwt: $config->jwt,
            nkey: 'test-nkey',
        );

        $encoded = $connect->encode();

        self::assertStringContainsString('"jwt":"eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0."', $encoded);
        self::assertStringContainsString('"nkey":"test-nkey"', $encoded);
        self::assertStringContainsString('"sig":"', $encoded);
        self::assertStringContainsString($signature, str_replace('\/', '/', $encoded));
    }

    public function testConnectProtocolCreationWithoutJwtAndNkey(): void
    {
        $config = new Config(
            user: 'admin',
            password: 'secret',
        );

        $connect = new Connect(
            verbose: $config->verbose,
            pedantic: $config->pedantic,
            tlsRequired: false,
            name: 'thesis/nats',
            version: $config->version,
            user: $config->user,
            pass: $config->password,
            sig: null,
            jwt: $config->jwt,
            nkey: null,
        );

        $encoded = $connect->encode();

        self::assertStringContainsString('"user":"admin"', $encoded);
        self::assertStringContainsString('"pass":"secret"', $encoded);
        self::assertStringNotContainsString('"jwt":', $encoded);
        self::assertStringNotContainsString('"nkey":', $encoded);
        self::assertStringNotContainsString('"sig":', $encoded);
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignerIntegrationWithConfig(): void
    {
        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';
        $nonce = 'integration-test-nonce';
        $signer = new Signer();
        $signature = $signer->sign($nonce, $nkey);

        self::assertEquals(88, \strlen($signature));

        $signature2 = $signer->sign($nonce, $nkey);
        self::assertEquals($signature, $signature2);

        $signature3 = $signer->sign('different-nonce', $nkey);
        self::assertNotEquals($signature, $signature3);
    }

    public function testConfigBackwardCompatibility(): void
    {
        $config = new Config(
            user: 'testuser',
            password: 'testpass',
        );

        $connect = new Connect(
            verbose: $config->verbose,
            pedantic: $config->pedantic,
            tlsRequired: false,
            name: 'thesis/nats',
            version: $config->version,
            authToken: null,
            user: $config->user,
            pass: $config->password,
        );

        $encoded = $connect->encode();

        self::assertStringContainsString('"user":"testuser"', $encoded);
        self::assertStringContainsString('"pass":"testpass"', $encoded);
        self::assertStringNotContainsString('"jwt":', $encoded);
        self::assertStringNotContainsString('"nkey":', $encoded);
    }

    #[RequiresPhpExtension('sodium')]
    public function testJwtAndNkeyPrecedenceOverUserPassword(): void
    {
        $config = new Config(
            user: 'olduser',
            password: 'oldpass',
            jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
            nkey: 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU',
        );

        $nkey = $config->nkey;
        self::assertNotNull($nkey);
        $signer = new Signer();
        $signature = $signer->sign('test-nonce', $nkey);

        $connect = new Connect(
            verbose: $config->verbose,
            pedantic: $config->pedantic,
            tlsRequired: false,
            name: 'thesis/nats',
            version: $config->version,
            user: $config->user,
            pass: $config->password,
            sig: $signature,
            jwt: $config->jwt,
            nkey: 'test-nkey',
        );

        $encoded = $connect->encode();

        self::assertStringContainsString('"jwt":"eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0."', $encoded);
        self::assertStringContainsString('"nkey":"test-nkey"', $encoded);
        self::assertStringContainsString('"sig":"', $encoded);
        self::assertStringContainsString($signature, str_replace('\/', '/', $encoded));

        self::assertStringContainsString('"user":"olduser"', $encoded);
        self::assertStringContainsString('"pass":"oldpass"', $encoded);
    }
}
