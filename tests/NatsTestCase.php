<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Internal\Rpc;

abstract class NatsTestCase extends TestCase
{
    /** @var non-empty-string */
    private string $dsn;

    /** @var non-empty-string */
    private string $jwtDsn;

    private ?Client $client = null;

    private ?Client $jwtClient = null;

    /** The client of the system account, used to manage the server from the tests. */
    private ?Client $sysClient = null;

    protected function setUp(): void
    {
        $dsn = getenv('THESIS_NATS_DSN');
        if (!\is_string($dsn) || $dsn === '') {
            self::markTestSkipped('THESIS_NATS_DSN must be set.');
        }

        $jwtDsn = getenv('THESIS_NATS_JWT_DSN');
        if (!\is_string($jwtDsn) || $jwtDsn === '') {
            self::markTestSkipped('THESIS_NATS_JWT_DSN must be set.');
        }

        $this->dsn = $dsn;

        $this->jwtDsn = $jwtDsn;
    }

    protected function tearDown(): void
    {
        $this->client?->stop();
        $this->jwtClient?->stop();
        $this->sysClient?->stop();
    }

    final protected function client(): Client
    {
        return $this->client = new Client(Config::fromURI($this->dsn));
    }

    final protected function clientWithJwtAuth(): Client
    {
        return $this->jwtClient = new Client(Config::fromURI($this->jwtDsn));
    }

    /**
     * Creates a client with the given config, stopped in tearDown like the default one.
     */
    final protected function clientWithConfig(Config $config): Client
    {
        return $this->client = new Client($config);
    }

    /**
     * Creates a client with the same credentials as the default one, but pointed at the given hosts.
     *
     * @param non-empty-list<non-empty-string> $urls
     */
    final protected function clientWithUrls(array $urls): Client
    {
        $config = Config::fromURI($this->dsn);

        return $this->clientWithConfig(new Config(
            urls: $urls,
            connectionTimeout: 1,
            user: $config->user,
            password: $config->password,
            noResponders: $config->noResponders,
        ));
    }

    /**
     * Creates a client with the same credentials as the default one, but connecting under the given name,
     * so that the server-side connection can be found, see kick().
     *
     * @param non-empty-string $name
     */
    final protected function clientNamed(string $name): Client
    {
        $config = $this->config();

        return $this->clientWithConfig(new Config(
            urls: $config->urls,
            user: $config->user,
            password: $config->password,
            noResponders: $config->noResponders,
            clientName: $name,
        ));
    }

    /**
     * Closes the connection of the client connected under the given name from the server side,
     * the same way `nats server kick` does it.
     *
     * @param non-empty-string $name
     */
    final protected function kick(string $name): void
    {
        $connection = $this->connectionNamed($name) ?? self::fail("The client '{$name}' is not connected.");

        self::decodeServerResponse(
            $this->sysClient()->request(
                "\$SYS.REQ.SERVER.{$connection['server']}.KICK",
                new Message(json_encode(['cid' => $connection['cid']], JSON_THROW_ON_ERROR)),
                new TimeoutCancellation(5),
            ),
        );
    }

    /**
     * Whether the server still has a connection of the client connected under the given name.
     *
     * @param non-empty-string $name
     */
    final protected function isConnected(string $name): bool
    {
        return $this->connectionNamed($name) !== null;
    }

    /**
     * @param non-empty-string $name
     * @return ?array{server: non-empty-string, cid: int}
     */
    private function connectionNamed(string $name): ?array
    {
        /** @var array{server: array{id: non-empty-string}, data: array{connections: list<array{cid: int, name?: string}>}} $connz */
        $connz = self::decodeServerResponse(
            $this->sysClient()->request('$SYS.REQ.SERVER.PING.CONNZ', new Message('{"acc": "CLIENT"}'), new TimeoutCancellation(5)),
        );

        foreach ($connz['data']['connections'] as $connection) {
            if (($connection['name'] ?? null) === $name) {
                return ['server' => $connz['server']['id'], 'cid' => $connection['cid']];
            }
        }

        return null;
    }

    private function sysClient(): Client
    {
        return $this->sysClient ??= new Client(new Config(
            urls: $this->config()->urls,
            user: 'admin',
            password: 'Pswd1',
            noResponders: true,
        ));
    }

    /**
     * @return int the number of the requests of the client still waiting for a reply
     */
    final protected static function pendingRequests(Client $client): int
    {
        /** @var ?Future<Rpc\Handler> $rpc */
        $rpc = new \ReflectionProperty(Client::class, 'rpc')->getValue($client);
        if ($rpc === null) {
            return 0;
        }

        /** @var array<array-key, mixed> $pendings */
        $pendings = new \ReflectionProperty(Rpc\Handler::class, 'pendings')->getValue($rpc->await());

        return \count($pendings);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeServerResponse(Delivery $delivery): array
    {
        /** @var array<string, mixed> $response */
        $response = json_decode($delivery->message->payload ?? '', associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('error', $response, $delivery->message->payload ?? '');

        return $response;
    }

    /**
     * @return Config the config of the default client
     */
    final protected function config(): Config
    {
        return Config::fromURI($this->dsn);
    }

    /**
     * @return Config the config of the client authenticated with JWT
     */
    final protected function jwtConfig(): Config
    {
        return Config::fromURI($this->jwtDsn);
    }

    /**
     * @return non-empty-string the host:port of the real nats server
     */
    final protected function natsAddress(): string
    {
        return $this->config()->urls[0];
    }
}
