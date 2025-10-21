<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
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
            nkey: null, // No NKey for JWT-only test
        );

        $encoded = $connect->encode();
        
        self::assertStringContainsString('"jwt":"eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0."', $encoded);
        self::assertStringNotContainsString('"nkey":', $encoded);
        self::assertStringNotContainsString('"sig":', $encoded);
    }

    public function testConnectProtocolCreationWithNkeyOnly(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $config = new Config(
            nkey: 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY',
        );

        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        $nonce = 'test-nonce';
        $signature = Signer::sign($nonce, $privateKey);

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
        self::assertStringContainsString($signature, str_replace('\\/', '/', $encoded));
        self::assertStringNotContainsString('"jwt":', $encoded);
    }

    public function testConnectProtocolCreationWithBothJwtAndNkey(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $config = new Config(
            jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
            nkey: 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY',
        );

        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        $nonce = 'test-nonce';
        $signature = Signer::sign($nonce, $privateKey);

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
        self::assertStringContainsString($signature, str_replace('\\/', '/', $encoded));
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

    public function testSignerIntegrationWithConfig(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        $nonce = 'integration-test-nonce';
        $signature = Signer::sign($nonce, $privateKey);
        
        self::assertNotNull($signature);
        self::assertIsString($signature);
        self::assertEquals(88, \strlen($signature));

        $signature2 = Signer::sign($nonce, $privateKey);
        self::assertEquals($signature, $signature2);
        
        $signature3 = Signer::sign('different-nonce', $privateKey);
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

    public function testJwtAndNkeyPrecedenceOverUserPassword(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $config = new Config(
            user: 'olduser',
            password: 'oldpass',
            jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
            nkey: 'SUAIBDPBAUTWCWBKIO6XNJWXXY2ELXAWYZSV3LBSRQTDHV5KIO4TWKX4CY',
        );

        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        $signature = Signer::sign('test-nonce', $privateKey);

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
        self::assertStringContainsString($signature, str_replace('\\/', '/', $encoded));
        
        self::assertStringContainsString('"user":"olduser"', $encoded);
        self::assertStringContainsString('"pass":"oldpass"', $encoded);
    }
}






