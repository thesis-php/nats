<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubscriptionIdGenerator::class)]
final class SubscriptionIdGeneratorTest extends TestCase
{
    public function testGenerateSubscriptionId(): void
    {
        $generator = new SubscriptionIdGenerator();

        for ($i = 1; $i < 11; ++$i) {
            self::assertSame("{$i}", $generator->nextId());
        }
    }
}
