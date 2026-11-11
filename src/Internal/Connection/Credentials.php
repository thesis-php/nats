<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Thesis\Nats\Config;
use Thesis\Nats\Exception\InvalidCredentials;
use Thesis\Nats\Exception\InvalidNKey;
use Thesis\Nats\Internal\Nkey\KeyPair;

/**
 * The JWT and the NKey of the user, taken from the config or read from the files it names.
 * The files are read on every connection attempt, so that rotated credentials are picked up, the same way nats.go does it.
 *
 * A file may hold the bare value or decorated blocks, as `nsc` generates them:
 *
 *     -----BEGIN NATS USER JWT-----
 *     eyJ0eXAiOiJKV1QiLCJhbGciOiJlZDI1NTE5LW5rZXkifQ...
 *     ------END NATS USER JWT------
 *
 *     -----BEGIN USER NKEY SEED-----
 *     SUAIO3FHUX5PNV2LQIIP7TZ3N4L7TX3W53MQGEIVYFIGA635OZCKEYHFLM
 *     ------END USER NKEY SEED------
 *
 * @internal
 */
final readonly class Credentials
{
    /** The same pattern as nats-io/jwt ParseDecoratedJWT/ParseDecoratedNKey use: a value between two dashed lines. */
    private const string DECORATED_BLOCK = '/\s*(?:(?:-{3,}.*-{3,}\r?\n)([\w\-.=]+)(?:\r?\n-{3,}.*-{3,}(?:\r?\n|\z)))/';

    /**
     * @param ?non-empty-string $jwt
     */
    private function __construct(
        #[\SensitiveParameter]
        public ?string $jwt = null,
        public ?KeyPair $keypair = null,
    ) {}

    /**
     * @throws InvalidCredentials
     * @throws InvalidNKey
     */
    public static function fromConfig(Config $config): self
    {
        if ($config->credentialsFile !== null) {
            $contents = self::read($config->credentialsFile);

            return new self(
                jwt: self::jwt($contents, $config->credentialsFile),
                keypair: KeyPair::fromSeed(self::seed($contents, $config->credentialsFile)),
            );
        }

        $jwt = $config->jwtFile !== null ? self::jwt(self::read($config->jwtFile), $config->jwtFile) : $config->jwt;

        $seed = $config->nkeyFile !== null ? self::seed(self::read($config->nkeyFile), $config->nkeyFile) : $config->nkey;

        return new self(
            jwt: $jwt,
            keypair: $seed !== null ? KeyPair::fromSeed($seed) : null,
        );
    }

    /**
     * @param non-empty-string $path
     * @throws InvalidCredentials
     */
    private static function read(string $path): string
    {
        // The files are a few hundred bytes: a blocking read is cheaper than a filesystem driver.
        // The last error is cleared first, so that an earlier unrelated one is not reported as the reason.
        error_clear_last();
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new InvalidCredentials(\sprintf(
                "The credentials file '%s' cannot be read: %s.",
                $path,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        return $contents;
    }

    /**
     * @param non-empty-string $path
     * @return non-empty-string the first decorated block, or the whole file if it has none
     * @throws InvalidCredentials
     */
    private static function jwt(#[\SensitiveParameter] string $contents, string $path): string
    {
        $jwt = self::blocks($contents)[0] ?? trim($contents);

        if ($jwt === '') {
            throw new InvalidCredentials("The credentials file '{$path}' holds no user JWT.");
        }

        return $jwt;
    }

    /**
     * @param non-empty-string $path
     * @return non-empty-string the second decorated block, or the first line that looks like a seed
     * @throws InvalidCredentials
     */
    private static function seed(#[\SensitiveParameter] string $contents, string $path): string
    {
        $blocks = self::blocks($contents);

        // A creds file holds the JWT first and the seed second, a seed file holds only the seed.
        if (\count($blocks) > 1) {
            return $blocks[1];
        }

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            // Operator, account and user seeds, as nats-io/jwt looks for them: KeyPair then accepts the user ones only.
            if (preg_match('/^S[OAU]/', $line) === 1) {
                return $line;
            }
        }

        throw new InvalidCredentials("The credentials file '{$path}' holds no NKey seed.");
    }

    /**
     * @return list<non-empty-string>
     */
    private static function blocks(#[\SensitiveParameter] string $contents): array
    {
        preg_match_all(self::DECORATED_BLOCK, $contents, $matches);

        /** @var list<non-empty-string> */
        return $matches[1];
    }
}
