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

        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';

        $nonce = 'test-nonce';
        $signature = Signer::sign($nonce, $nkey);

        self::assertIsString($signature);
        self::assertNotEmpty($signature);
        self::assertEquals(88, \strlen($signature));
    }

    public function testSignConsistency(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';

        $nonce = 'consistent-test-nonce';

        $signature1 = Signer::sign($nonce, $nkey);
        $signature2 = Signer::sign($nonce, $nkey);
        self::assertEquals($signature1, $signature2);

        $signature3 = Signer::sign('different-nonce', $nkey);
        self::assertNotEquals($signature1, $signature3);
    }

    public function testSignWithDifferentValidKeys(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $nkey1 = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';
        $nkey2 = 'SUAHZXJ5Y3KIRG3QJLP36DOLIW2GD7X2E7NLLDCK6ASBYNMGG4CVWIL6RA';

        $nonce = 'same-nonce';
        $signature1 = Signer::sign($nonce, $nkey1);

        try {
            $signature2 = Signer::sign($nonce, $nkey2);
            self::assertNotEquals($signature1, $signature2);
        } catch (\Exception $e) {
            self::assertStringContainsString('Failed to decode NKey from Base32', $e->getMessage());
        }
    }

    public function testSignWithInvalidNKeyFormat(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid NKey format: expected user seed key starting with "SU"');

        Signer::sign('test-nonce', 'INVALID_KEY_FORMAT');
    }

    public function testSignWithNonUserSeedKey(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid NKey format: expected user seed key starting with "SU"');

        Signer::sign('test-nonce', 'SA' . str_repeat('A', 56));
    }

    public function testSignWithInvalidBase32Characters(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid NKey: insufficient length after decoding');

        Signer::sign('test-nonce', 'SUAILOU' . str_repeat('A', 50));
    }

    public function testSignWithTooShortNKey(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to decode NKey from Base32');

        Signer::sign('test-nonce', 'SUABC123');
    }

    public function testSignatureVerification(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';
        $nonce = 'verification-test-nonce';


        $signature = Signer::sign($nonce, $nkey);

        $decodedSignature = base64_decode($signature, strict: true);
        self::assertNotFalse($decodedSignature);
        self::assertEquals(64, \strlen($decodedSignature));

    }

    public function testSignWithEmptyNonce(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Nonce cannot be empty');

        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';
        Signer::sign('', $nkey);
    }

    public function testSignWithEmptyNKey(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension is not available.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('NKey cannot be empty');

        Signer::sign('test-nonce', '');
    }
}
