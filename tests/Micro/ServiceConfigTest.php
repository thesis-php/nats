<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceConfig::class)]
final class ServiceConfigTest extends TestCase
{
    public function testInvalidServiceName(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new ServiceConfig('foo.>', '1.0.0');
    }

    public function testInvalidServiceVersion(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new ServiceConfig('foo', '1');
    }

    public function testValidConfig(): void
    {
        $config = new ServiceConfig('foo', '1.0.0');
        self::assertSame('foo', $config->name);
        self::assertSame('1.0.0', $config->version);
    }
}
