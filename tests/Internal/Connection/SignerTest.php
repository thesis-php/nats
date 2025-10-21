<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Signer::class)]
final class SignerTest extends TestCase
{
    public function testSignWithValidNkey(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        
        $nonce = 'test-nonce';
        $signature = Signer::sign($nonce, $privateKey);
        
        self::assertIsString($signature);
        self::assertNotEmpty($signature);
        self::assertEquals(88, \strlen($signature));
        
        $decoded = base64_decode($signature, true);
        self::assertNotFalse($decoded);
        self::assertEquals(64, \strlen($decoded));
    }

    public function testSignConsistency(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        
        $nonce = 'consistent-test-nonce';
        
        $signature1 = Signer::sign($nonce, $privateKey);
        $signature2 = Signer::sign($nonce, $privateKey);
        self::assertEquals($signature1, $signature2);
        
        $signature3 = Signer::sign('different-nonce', $privateKey);
        self::assertNotEquals($signature1, $signature3);
    }

    public function testSignWithInvalidKey(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $this->expectException(\SodiumException::class);
        
        Signer::sign('test-nonce', 'invalid-key');
    }

    public function testSignatureVerification(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        
        $nonce = 'verification-test-nonce';
        $signature = Signer::sign($nonce, $privateKey);
        
        $decodedSignature = base64_decode($signature);
        $isValid = sodium_crypto_sign_verify_detached($decodedSignature, $nonce, $publicKey);
        
        self::assertTrue($isValid, 'Generated signature should be verifiable with corresponding public key');
    }
}