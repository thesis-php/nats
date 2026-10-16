<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\AuthorizationViolation;
use Thesis\Nats\Exception\MaximumControlLineExceeded;
use Thesis\Nats\Exception\MaximumPayloadViolation;
use Thesis\Nats\Exception\PermissionViolated;

#[CoversClass(Err::class)]
final class ErrTest extends TestCase
{
    /**
     * @param non-empty-string $message
     * @param ?array{non-empty-string, ?non-empty-string} $subscription
     */
    #[TestWith(['Permissions Violation for Subscription to "events.>"', ['events.>', null]])]
    #[TestWith(['Permissions Violation for Subscription to "events.*" using queue "workers"', ['events.*', 'workers']])]
    #[TestWith(['Permissions Violation for Publish to "events.happens"', null])]
    #[TestWith(['Permissions Violation for Publish with Reply of "events.happens"', null])]
    #[TestWith(['Authorization Violation', null])]
    public function testViolatedSubscription(string $message, ?array $subscription): void
    {
        self::assertSame($subscription, new Err($message)->violatedSubscription());
    }

    public function testToException(): void
    {
        self::assertInstanceOf(AuthorizationViolation::class, new Err('Authorization Violation')->toException());
        self::assertInstanceOf(MaximumPayloadViolation::class, new Err('Maximum Payload Violation')->toException());

        $e = new Err('maximum control line exceeded: State 4, max_control_line 4096, Buffer len 4100')->toException();
        self::assertInstanceOf(MaximumControlLineExceeded::class, $e);
        self::assertSame('maximum control line exceeded: State 4, max_control_line 4096, Buffer len 4100', $e->getMessage());

        $e = new Err('Permissions Violation for Publish to "events.happens"')->toException();
        self::assertInstanceOf(PermissionViolated::class, $e);
        self::assertSame('Permissions Violation for Publish to "events.happens"', $e->getMessage());

        $e = new Err('Something Unexpected')->toException();
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('Something Unexpected', $e->getMessage());
    }
}
