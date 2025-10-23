<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(Signer::class)]
final class SignerTest extends TestCase
{
    #[RequiresPhpExtension('sodium')]
    public function testSignWithValidNkey(): void
    {
        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';

        $nonce = 'test-nonce';
        $signer = new Signer();
        $signature = $signer->sign($nonce, $nkey);

        self::assertNotEmpty($signature);
        self::assertEquals(88, \strlen($signature));
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignConsistency(): void
    {
        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';

        $nonce = 'consistent-test-nonce';

        $signer = new Signer();
        $signature1 = $signer->sign($nonce, $nkey);
        $signature2 = $signer->sign($nonce, $nkey);
        self::assertEquals($signature1, $signature2);

        $signature3 = $signer->sign('different-nonce', $nkey);
        self::assertNotSame($signature1, $signature3);
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignWithDifferentValidKeys(): void
    {
        $nkey1 = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';
        $nkey2 = 'SUAHZXJ5Y3KIRG3QJLP36DOLIW2GD7X2E7NLLDCK6ASBYNMGG4CVWIL6RA';

        $nonce = 'same-nonce';
        $signer = new Signer();
        $signature1 = $signer->sign($nonce, $nkey1);

        $signature2 = $signer->sign($nonce, $nkey2);
        self::assertNotSame($signature1, $signature2);
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignWithInvalidNKeyFormat(): void
    {
        self::expectException(\Exception::class);
        self::expectExceptionMessage('Invalid NKey format: expected user seed key starting with "SU"');

        $signer = new Signer();
        $signer->sign('test-nonce', 'INVALID_KEY_FORMAT');
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignWithNonUserSeedKey(): void
    {
        self::expectException(\Exception::class);
        self::expectExceptionMessage('Invalid NKey format: expected user seed key starting with "SU"');

        $signer = new Signer();
        $signer->sign('test-nonce', 'SA' . str_repeat('A', 56));
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignWithInvalidBase32Characters(): void
    {
        self::expectException(\Exception::class);
        self::expectExceptionMessage('Invalid NKey: insufficient length after decoding');

        $signer = new Signer();
        $signer->sign('test-nonce', 'SUAILOU' . str_repeat('A', 50));
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignWithTooShortNKey(): void
    {
        self::expectException(\Exception::class);
        self::expectExceptionMessage('Failed to decode NKey from Base32');

        $signer = new Signer();
        $signer->sign('test-nonce', 'SUABC123');
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignatureVerification(): void
    {
        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';
        $nonce = 'verification-test-nonce';

        $signer = new Signer();
        $signature = $signer->sign($nonce, $nkey);

        $decodedSignature = base64_decode($signature, strict: true);
        self::assertNotFalse($decodedSignature);
        self::assertEquals(64, \strlen($decodedSignature));

    }

    #[RequiresPhpExtension('sodium')]
    public function testSignWithEmptyNonce(): void
    {
        self::expectException(\Exception::class);
        self::expectExceptionMessage('Nonce cannot be empty');

        $nkey = 'SUAEZDQZKIU5Q7X5IWM7NDETHW4HEPXKNHI44TX3RKWXASGY74YQL5N6XU';
        $signer = new Signer();
        $signer->sign('', $nkey);
    }

    #[RequiresPhpExtension('sodium')]
    public function testSignWithEmptyNKey(): void
    {
        self::expectException(\Exception::class);
        self::expectExceptionMessage('NKey cannot be empty');

        $signer = new Signer();
        $signer->sign('test-nonce', '');
    }
}
