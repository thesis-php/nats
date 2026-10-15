<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingLimits::class)]
final class PendingLimitsTest extends TestCase
{
    public function testDefaultsAreTheOnesOfNatsGo(): void
    {
        $limits = new PendingLimits();

        self::assertSame(512 * 1024, $limits->messages);
        self::assertSame(64 * 1024 * 1024, $limits->bytes);
    }

    public function testUnlimited(): void
    {
        self::assertEquals(new PendingLimits(messages: null, bytes: null), PendingLimits::unlimited());
    }

    #[TestWith([0, null])]
    #[TestWith([-1, null])]
    #[TestWith([null, 0])]
    #[TestWith([null, -1])]
    public function testLimitsMustBePositive(?int $messages, ?int $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type, argument.type */
        new PendingLimits($messages, $bytes);
    }
}
