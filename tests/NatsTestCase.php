<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\TestCase;

abstract class NatsTestCase extends TestCase
{
    /** @var non-empty-string */
    private string $dsn;

    /** @var non-empty-string */
    private string $jwtDsn;

    private ?Client $client = null;

    private ?Client $jwtClient = null;

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
     * Creates a client with the same credentials as the default one, but pointed at the given hosts.
     *
     * @param non-empty-list<non-empty-string> $urls
     */
    final protected function clientWithUrls(array $urls): Client
    {
        $config = Config::fromURI($this->dsn);

        return $this->client = new Client(new Config(
            urls: $urls,
            connectionTimeout: 1,
            user: $config->user,
            password: $config->password,
            noResponders: $config->noResponders,
        ));
    }

    /**
     * @return non-empty-string the host:port of the real nats server
     */
    final protected function natsAddress(): string
    {
        return Config::fromURI($this->dsn)->urls[0];
    }
}
