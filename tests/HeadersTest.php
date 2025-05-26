<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Headers::class)]
final class HeadersTest extends TestCase
{
    public function testHeaders(): void
    {
        $headers = new Headers();

        $headers = $headers->with('x', 'y');
        self::assertCount(1, $headers);
        self::assertSame(['x' => ['y']], [...$headers]);
        self::assertSame('y', $headers->get('x'));
        self::assertSame(['y'], $headers->values('x'));

        $headers = $headers->withAdded('x', 'z');
        self::assertCount(1, $headers);
        self::assertSame(['x' => ['y', 'z']], [...$headers]);
        self::assertSame('y', $headers->get('x'));
        self::assertSame(['y', 'z'], $headers->values('x'));

        $headers = $headers->with('x', 'y');
        self::assertCount(1, $headers);
        self::assertSame(['x' => ['y']], [...$headers]);
        self::assertSame('y', $headers->get('x'));
        self::assertSame(['y'], $headers->values('x'));

        $headers = $headers->without('x');
        self::assertCount(0, $headers);
        self::assertSame([], [...$headers]);
        self::assertNull($headers->get('x'));
        self::assertCount(0, $headers->values('x'));

        self::assertSame('200', $headers->get(Header\StatusCode::Header));
        $headers = $headers->with(Header\StatusCode::Header, '500');
        self::assertSame('500', $headers->get(Header\StatusCode::Header));
    }
}
