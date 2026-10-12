<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Nkey;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\InvalidNKey;

#[CoversClass(KeyPair::class)]
#[RequiresPhpExtension('sodium')]
final class KeyPairTest extends TestCase
{
    /** The user seed from the nk tool documentation (https://docs.nats.io/using-nats/developer/connecting/nkey). */
    private const string SEED = 'SUACSSL3UAHUDXKFSNVUZRF5UHPMWZ6BFDTJ7M6USDXIEDNPPQYYYCU3VY';

    /** The public key printed by `nk -inkey` for self::SEED. */
    private const string PUBLIC_KEY = 'UDXU4RCSJNZOIQHZNWXHXORDPRTGNJAHAHFRGZNEEJCPQTT2M7NLCNF4';
    private const string OTHER_SEED = 'SUAHNDAT4XZP3JOFBU5STXVC7473S7UBIOSJBUETHPUFHAMZIW7PY73WUM';

    public function testPublicKeyIsDerivedFromSeed(): void
    {
        self::assertSame(self::PUBLIC_KEY, KeyPair::fromSeed(self::SEED)->publicKey);
    }

    public function testSeedIsNormalized(): void
    {
        self::assertSame(self::PUBLIC_KEY, KeyPair::fromSeed(" \t" . strtolower(self::SEED) . "\n")->publicKey);
    }

    public function testSignatureIsVerifiableByPublicKey(): void
    {
        $keypair = KeyPair::fromSeed(self::SEED);
        $signature = $keypair->sign('nonce');

        // nats.go encodes the signature with base64.RawURLEncoding: no padding, url-safe alphabet.
        self::assertSame(86, \strlen($signature));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $signature);

        $decoded = sodium_base642bin($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        self::assertSame(SODIUM_CRYPTO_SIGN_BYTES, \strlen($decoded));

        self::assertTrue(sodium_crypto_sign_verify_detached($decoded, 'nonce', self::decodePublicKey(self::PUBLIC_KEY)));
        self::assertFalse(sodium_crypto_sign_verify_detached($decoded, 'other nonce', self::decodePublicKey(self::PUBLIC_KEY)));
    }

    public function testSignatureDependsOnNonceAndSeed(): void
    {
        $keypair = KeyPair::fromSeed(self::SEED);

        self::assertSame($keypair->sign('nonce'), $keypair->sign('nonce'));
        self::assertNotSame($keypair->sign('nonce'), $keypair->sign('other nonce'));
        self::assertNotSame($keypair->sign('nonce'), KeyPair::fromSeed(self::OTHER_SEED)->sign('nonce'));
    }

    #[TestWith(['', 'NKey seed cannot be empty.'])]
    #[TestWith(['   ', 'NKey seed cannot be empty.'])]
    #[TestWith(['SU!' . self::SEED, 'NKey seed is not a valid base32 string.'])]
    #[TestWith(['SUABCDEF', 'NKey seed has invalid length: expected 36 bytes, got 5.'])]
    // 59 characters would decode to 36 bytes as well, but the dangling character is not what nk produces.
    #[TestWith([self::SEED . 'A', 'NKey seed is not a valid base32 string.'])]
    #[TestWith([self::PUBLIC_KEY, 'NKey seed has invalid length: expected 36 bytes, got 35.'])]
    // The last character of the seed is replaced: the CRC does not match anymore.
    #[TestWith(['SUACSSL3UAHUDXKFSNVUZRF5UHPMWZ6BFDTJ7M6USDXIEDNPPQYYYCU3VA', 'NKey seed is corrupted: CRC check failed.'])]
    // An account seed ("SA") is not accepted for the user authentication.
    #[TestWith(['SAAIK7GVSFTRKP3EE4DQCRZ6MG6SNAQ2BCN2F3QKURXYBVQGVNVZW5XGVQ', 'Invalid NKey format: expected a user seed starting with "SU".'])]
    public function testInvalidSeedIsRejected(string $seed, string $message): void
    {
        self::expectException(InvalidNKey::class);
        self::expectExceptionMessage($message);

        KeyPair::fromSeed($seed);
    }

    /**
     * @param non-empty-string $publicKey
     * @return non-empty-string the raw 32 bytes of the ed25519 public key
     */
    private static function decodePublicKey(string $publicKey): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $decoded = '';
        $buffer = 0;
        $bits = 0;

        for ($i = 0, $length = \strlen($publicKey); $i < $length; ++$i) {
            $value = strpos($alphabet, $publicKey[$i]);
            self::assertNotFalse($value);

            $buffer = (($buffer << 5) | $value) & 0xFFFF;
            $bits += 5;

            if ($bits >= 8) {
                $decoded .= \chr(($buffer >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        // prefix byte 'U', 32 bytes of the key, 2 bytes of the CRC
        self::assertSame(35, \strlen($decoded));
        self::assertSame(20 << 3, \ord($decoded[0]));

        /** @var non-empty-string */
        return substr($decoded, 1, 32);
    }
}
