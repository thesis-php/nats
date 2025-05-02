<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

#[CoversFunction('generateSubscriptionId')]
final class GenerateSubscriptionIdTest extends TestCase
{
    public function testGenerateSubscriptionId(): void
    {
        for ($i = 1; $i < 11; ++$i) {
            self::assertSame("{$i}", generateSubscriptionId());
        }
    }
}
