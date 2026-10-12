<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Nkey;

use Thesis\Nats\Exception\InvalidNKey;

/**
 * An ed25519 key pair restored from a user seed, encoded the way https://github.com/nats-io/nkeys does it:
 * base32 (RFC 4648, no padding) of the prefix byte(s), the key material and a little-endian CRC16/XMODEM.
 *
 * @internal
 */
final readonly class KeyPair
{
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** The prefix byte of a seed ('S'). */
    private const int PREFIX_SEED = 18 << 3;

    /** The prefix byte of a user key ('U'). */
    private const int PREFIX_USER = 20 << 3;

    /** 2 prefix bytes + 32 bytes of the ed25519 seed + 2 bytes of the CRC. */
    private const int SEED_LENGTH = 36;

    /**
     * @param non-empty-string $publicKey the encoded public user key ('U...') to send to the server
     * @param non-empty-string $secretKey the raw ed25519 secret key
     */
    private function __construct(
        public string $publicKey,
        #[\SensitiveParameter]
        private string $secretKey,
    ) {}

    /**
     * @param string $seed the encoded user seed ('SU...')
     * @throws InvalidNKey
     */
    public static function fromSeed(#[\SensitiveParameter] string $seed): self
    {
        if (!\extension_loaded('sodium')) {
            throw new InvalidNKey('ext-sodium is required for NKey authentication.');
        }

        $seed = strtoupper(trim($seed));

        if ($seed === '') {
            throw new InvalidNKey('NKey seed cannot be empty.');
        }

        $decoded = self::base32Decode($seed) ?? throw new InvalidNKey('NKey seed is not a valid base32 string.');

        if (\strlen($decoded) !== self::SEED_LENGTH) {
            throw new InvalidNKey(\sprintf(
                'NKey seed has invalid length: expected %d bytes, got %d.',
                self::SEED_LENGTH,
                \strlen($decoded),
            ));
        }

        $body = substr($decoded, 0, self::SEED_LENGTH - 2);

        if (self::crc16($body) !== self::littleEndianUInt16(substr($decoded, self::SEED_LENGTH - 2))) {
            throw new InvalidNKey('NKey seed is corrupted: CRC check failed.');
        }

        // The seed prefix byte keeps the upper 3 bits of the role prefix byte in its lower 3 bits,
        // the remaining 5 bits of the role prefix byte are in the upper bits of the second byte.
        $prefix = \ord($decoded[0]) & 0xF8;
        $role = ((\ord($decoded[0]) & 0x07) << 5) | (\ord($decoded[1]) >> 3);

        if ($prefix !== self::PREFIX_SEED || $role !== self::PREFIX_USER) {
            throw new InvalidNKey('Invalid NKey format: expected a user seed starting with "SU".');
        }

        /** @var non-empty-string $material */
        $material = substr($decoded, 2, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $keypair = sodium_crypto_sign_seed_keypair($material);

        return new self(
            publicKey: self::encode(\chr(self::PREFIX_USER) . sodium_crypto_sign_publickey($keypair)),
            secretKey: sodium_crypto_sign_secretkey($keypair),
        );
    }

    /**
     * @param non-empty-string $nonce
     * @return string the signature in base64 without padding using the url-safe alphabet, like nats.go
     */
    public function sign(string $nonce): string
    {
        $signature = sodium_crypto_sign_detached($nonce, $this->secretKey);

        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * @param non-empty-string $payload
     * @return non-empty-string
     */
    private static function encode(string $payload): string
    {
        /** @var non-empty-string */
        return self::base32Encode($payload . pack('v', self::crc16($payload)));
    }

    /**
     * CRC16/XMODEM: polynomial 0x1021, initial value 0, no reflection.
     */
    private static function crc16(string $data): int
    {
        $crc = 0;

        for ($i = 0, $length = \strlen($data); $i < $length; ++$i) {
            $crc ^= \ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; ++$bit) {
                $crc = (($crc & 0x8000) !== 0 ? ($crc << 1) ^ 0x1021 : $crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }

    private static function littleEndianUInt16(string $bytes): int
    {
        return \ord($bytes[0]) | (\ord($bytes[1]) << 8);
    }

    private static function base32Encode(string $input): string
    {
        $output = '';
        $buffer = 0;
        $bits = 0;

        for ($i = 0, $length = \strlen($input); $i < $length; ++$i) {
            $buffer = (($buffer << 8) | \ord($input[$i])) & 0xFFFF;
            $bits += 8;

            while ($bits >= 5) {
                $output .= self::BASE32_ALPHABET[($buffer >> ($bits - 5)) & 0x1F];
                $bits -= 5;
            }
        }

        if ($bits > 0) {
            $output .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }

        return $output;
    }

    /**
     * @param non-empty-string $input
     * @return ?string null if the input contains characters outside the alphabet or has a dangling character at the end
     */
    private static function base32Decode(string $input): ?string
    {
        $output = '';
        $buffer = 0;
        $bits = 0;

        for ($i = 0, $length = \strlen($input); $i < $length; ++$i) {
            $value = strpos(self::BASE32_ALPHABET, $input[$i]);

            if ($value === false) {
                return null;
            }

            $buffer = (($buffer << 5) | $value) & 0xFFFF;
            $bits += 5;

            if ($bits >= 8) {
                $output .= \chr(($buffer >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        // A whole unused character at the end: the input is not a canonical unpadded base32 string.
        if ($bits >= 5) {
            return null;
        }

        return $output;
    }
}
