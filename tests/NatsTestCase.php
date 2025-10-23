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
        parent::setUp();

        $dsn = getenv('THESIS_NATS_DSN');
        if (!\is_string($dsn) || $dsn === '') {
            self::markTestSkipped('THESIS_NATS_DSN must be set.');
        }

        $dsn = getenv('THESIS_NATS_JWT_DSN');
        if (!\is_string($dsn) || $dsn === '') {
            self::markTestSkipped('THESIS_NATS_JWT_DSN must be set.');
        }

        $this->dsn = $dsn;

        $this->jwtDsn = $dsn;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->client?->disconnect();
        $this->jwtClient?->disconnect();
    }

    final protected function client(): Client
    {
        return $this->client = new Client(Config::fromURI($this->dsn));
    }

    final protected function clientWithJwtAuth(): Client
    {
        return $this->jwtClient = new Client(Config::fromURI($this->jwtDsn));
    }
}
