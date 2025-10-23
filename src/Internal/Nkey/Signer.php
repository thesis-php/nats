<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Nkey;

/**
 * @internal
 */
final readonly class Signer
{
    /**
     * NATS Base32 alphabet (RFC 4648 Base32).
     */
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @throws \Exception
     */
    public function sign(string $nonce, string $nkey): string
    {
        if (!\extension_loaded('sodium')) {
            throw new \Exception('ext-sodium extension is required for NKey authentication');
        }

        if ($nonce === '') {
            throw new \Exception('Nonce cannot be empty');
        }

        if ($nkey === '') {
            throw new \Exception('NKey cannot be empty');
        }

        $binaryKey = $this->decodeNKey($nkey);

        $signature = sodium_crypto_sign_detached($nonce, $binaryKey);

        return base64_encode($signature);
    }

    /**
     * @return non-empty-string
     * @throws \Exception
     */
    private function decodeNKey(string $nkey): string
    {
        $nkey = strtoupper(trim($nkey));

        if (!str_starts_with($nkey, 'SU')) {
            throw new \Exception('Invalid NKey format: expected user seed key starting with "SU"');
        }

        $decoded = $this->base32Decode($nkey);

        if ($decoded === false) {
            throw new \Exception('Failed to decode NKey from Base32');
        }

        if (\strlen($decoded) < 36) {
            throw new \Exception('Invalid NKey: insufficient length after decoding');
        }

        $seed = substr($decoded, 2, 32);

        if (\strlen($seed) !== 32) {
            throw new \Exception('Invalid seed: must be exactly 32 bytes');
        }

        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $privateKey = sodium_crypto_sign_secretkey($keypair);

        if (\strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \Exception('Invalid private key length');
        }

        return $privateKey;
    }

    /**
     * Decode Base32 string using NATS alphabet.
     */
    private function base32Decode(string $input): string|false
    {
        if ($input === '') {
            return false;
        }

        $alphabet = self::BASE32_ALPHABET;
        $inputLength = \strlen($input);
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < $inputLength; ++$i) {
            $char = $input[$i];
            $val = strpos($alphabet, $char);

            if ($val === false) {
                return false;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $output .= \chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
                $bitsLeft -= 8;
            }
        }

        return $output;
    }
}
