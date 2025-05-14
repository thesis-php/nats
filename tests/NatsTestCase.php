<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\TestCase;

abstract class NatsTestCase extends TestCase
{
    /** @var non-empty-string */
    private string $dsn;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('THESIS_NATS_DSN');
        if (!\is_string($dsn) || $dsn === '') {
            self::markTestSkipped('THESIS_NATS_DSN must be set.');
        }

        $this->dsn = $dsn;
    }

    final protected function client(): Client
    {
        return new Client(Config::fromURI($this->dsn));
    }
}
