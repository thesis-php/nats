<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Thesis\Nats\Exception\AuthorizationViolation;
use Thesis\Nats\Exception\InvalidCredentials;
use Thesis\Nats\Internal\Connection\Credentials;
use Thesis\Nats\Internal\Id;
use function Amp\async;
use function Amp\Socket\listen;

/**
 * Token authentication and the credentials read from files: the user JWT and the NKey seed of the JWT server.
 */
#[CoversClass(Client::class)]
#[CoversClass(Config::class)]
#[CoversClass(Credentials::class)]
final class AuthenticationTest extends NatsTestCase
{
    /** A valid user seed the JWT server does not know: its signature does not match the public key of the JWT. */
    private const string UNKNOWN_SEED = 'SUAHNDAT4XZP3JOFBU5STXVC7473S7UBIOSJBUETHPUFHAMZIW7PY73WUM';

    /** @var list<non-empty-string> */
    private array $files = [];

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testConnectsWithTokenAsUserOfUri(): void
    {
        // nats://token@host: a user without a password is a token, as in nats.go.
        $config = Config::fromURI($this->tokenDsn());

        self::assertNotNull($config->token);
        self::assertNull($config->user);

        self::assertPublishesAndReceives($this->clientWithConfig($config));
    }

    public function testConnectsWithTokenOption(): void
    {
        $token = Config::fromURI($this->tokenDsn())->token;
        self::assertNotNull($token);

        $config = Config::fromURI("nats://{$this->tokenAddress()}?token={$token}");
        self::assertSame($token, $config->token);

        self::assertPublishesAndReceives($this->clientWithConfig($config));
    }

    public function testUserWithEmptyPasswordIsNotToken(): void
    {
        // The colon makes it a user with an empty password, as the server knows it, not the token "nopass".
        $config = Config::fromURI("nats://nopass:@{$this->natsAddress()}");

        self::assertSame('nopass', $config->user);
        self::assertNull($config->token);

        self::assertPublishesAndReceives($this->clientWithConfig($config));
    }

    public function testWrongTokenIsRejectedOnConnect(): void
    {
        $client = $this->clientWithConfig(new Config(urls: [$this->tokenAddress()], token: 'wrong'));

        self::expectException(AuthorizationViolation::class);

        $client->connect(new TimeoutCancellation(5));
    }

    #[RequiresPhpExtension('sodium')]
    public function testConnectsWithCredentialsFile(): void
    {
        [$jwt, $seed] = $this->jwtCredentials();

        $client = $this->clientWithConfig(new Config(
            urls: $this->jwtConfig()->urls,
            credentialsFile: $this->file(self::decoratedCredentials($jwt, $seed)),
        ));

        self::assertSame(-1, $client->jetStream()->accountInfo()->limits->maxMemory);
    }

    #[RequiresPhpExtension('sodium')]
    public function testConnectsWithJwtAndNkeyFiles(): void
    {
        [$jwt, $seed] = $this->jwtCredentials();

        // A bare JWT and a seed decorated as nsc writes it, both are accepted.
        $client = $this->clientWithConfig(Config::fromURI(\sprintf(
            'nats://%s?jwt_file=%s&nkey_file=%s',
            $this->jwtConfig()->urls[0],
            rawurlencode($this->file("{$jwt}\n")),
            rawurlencode($this->file("-----BEGIN USER NKEY SEED-----\n{$seed}\n------END USER NKEY SEED------\n")),
        )));

        self::assertSame(-1, $client->jetStream()->accountInfo()->limits->maxMemory);
    }

    #[RequiresPhpExtension('sodium')]
    public function testCredentialsFileIsReadOnEveryConnectionAttempt(): void
    {
        [$jwt, $seed] = $this->jwtCredentials();
        $file = $this->file(self::decoratedCredentials($jwt, self::UNKNOWN_SEED));

        $client = $this->clientWithConfig(new Config(urls: $this->jwtConfig()->urls, credentialsFile: $file));

        try {
            $client->connect(new TimeoutCancellation(5));
            self::fail('The seed does not match the JWT, connect must fail.');
        } catch (AuthorizationViolation) {
        }

        // The rotated credentials are picked up by the next attempt of the same client.
        file_put_contents($file, self::decoratedCredentials($jwt, $seed));

        $client->connect(new TimeoutCancellation(5));
        self::assertSame(-1, $client->jetStream()->accountInfo()->limits->maxMemory);
    }

    public function testMissingCredentialsFileFailsBeforeConnecting(): void
    {
        $server = listen('127.0.0.1:0');
        $address = $server->getAddress()->toString();
        \assert($address !== '');

        $accepted = async($server->accept(...));

        $client = $this->clientWithConfig(new Config(
            urls: [$address],
            credentialsFile: \sprintf('%s/%s.creds', sys_get_temp_dir(), Id\generateUniqueId()),
        ));

        try {
            $client->connect(new TimeoutCancellation(5));
            self::fail('The credentials file does not exist, connect must fail.');
        } catch (InvalidCredentials $e) {
            self::assertStringContainsString('cannot be read', $e->getMessage());
        }

        $server->close();
        // Nothing has been connected to the server.
        self::assertNull($accepted->await(new TimeoutCancellation(1)));
    }

    public function testCredentialsFileWithoutSeedIsRejected(): void
    {
        [$jwt] = $this->jwtCredentials();

        $client = $this->clientWithConfig(new Config(
            urls: $this->jwtConfig()->urls,
            credentialsFile: $this->file("-----BEGIN NATS USER JWT-----\n{$jwt}\n------END NATS USER JWT------\n"),
        ));

        self::expectException(InvalidCredentials::class);
        self::expectExceptionMessage('holds no NKey seed');

        $client->connect(new TimeoutCancellation(5));
    }

    private static function assertPublishesAndReceives(Client $client): void
    {
        $client->connect(new TimeoutCancellation(5));

        $subject = Id\generateUniqueId();
        $deliveries = $client->subscribeIterator($subject);
        $client->publish($subject, new Message('ok'));

        foreach ($deliveries as $delivery) {
            self::assertSame('ok', $delivery->message->payload);

            $deliveries->stop();
        }
    }

    /**
     * @param non-empty-string $jwt
     * @param non-empty-string $seed
     * @return non-empty-string a user credentials file in the format of nsc
     */
    private static function decoratedCredentials(string $jwt, string $seed): string
    {
        return <<<CREDS
            -----BEGIN NATS USER JWT-----
            {$jwt}
            ------END NATS USER JWT------

            ************************* IMPORTANT *************************
            NKEY Seed printed below can be used to sign and prove identity.
            NKEYs are sensitive and should be treated as secrets.

            -----BEGIN USER NKEY SEED-----
            {$seed}
            ------END USER NKEY SEED------

            *************************************************************

            CREDS;
    }

    /**
     * @return array{non-empty-string, non-empty-string} the user JWT and the seed the JWT server accepts
     */
    private function jwtCredentials(): array
    {
        $config = $this->jwtConfig();

        return [
            $config->jwt ?? self::fail('The JWT dsn has no jwt.'),
            $config->nkey ?? self::fail('The JWT dsn has no nkey.'),
        ];
    }

    /**
     * @return non-empty-string the path of a temporary file with the given contents, removed in tearDown
     */
    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'nats');
        \assert($file !== false);

        file_put_contents($file, $contents);

        return $this->files[] = $file;
    }

    /**
     * @return non-empty-string
     */
    private function tokenDsn(): string
    {
        $dsn = getenv('THESIS_NATS_TOKEN_DSN');
        if (!\is_string($dsn) || $dsn === '') {
            self::markTestSkipped('THESIS_NATS_TOKEN_DSN must be set.');
        }

        return $dsn;
    }

    /**
     * @return non-empty-string
     */
    private function tokenAddress(): string
    {
        return Config::fromURI($this->tokenDsn())->urls[0];
    }
}
