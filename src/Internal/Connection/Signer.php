<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Exception;

/**
 * @internal
 */
final class Signer
{
    /**
     * @throws Exception
     */
    public static function sign(string $nonce, string $nkey): string
    {
        if (!\extension_loaded('sodium')) {
            throw new \Exception('ext-sodium extension is required for NKey authentication');
        }

        $signature = sodium_crypto_sign_detached($nonce, $nkey);

        return base64_encode($signature);
    }
}