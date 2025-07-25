<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Header\ScalarKey;
use Thesis\Time\TimeSpan;

#[CoversClass(Headers::class)]
final class HeadersTest extends TestCase
{
    public function testHeaders(): void
    {
        $headers = new Headers();

        self::assertFalse($headers->exists(ScalarKey::string('x')));

        $headers = $headers->with(ScalarKey::string('x'), 'y');
        self::assertCount(1, $headers);
        self::assertSame(['x' => ['y']], [...$headers]);
        self::assertSame('y', $headers->get(ScalarKey::string('x')));
        self::assertSame(['y'], $headers->values(ScalarKey::string('x')));
        self::assertTrue($headers->exists(ScalarKey::string('x')));

        $headers = $headers->with(ScalarKey::string('x'), 'z');
        self::assertCount(1, $headers);
        self::assertSame(['x' => ['y', 'z']], [...$headers]);
        self::assertSame('y', $headers->get(ScalarKey::string('x')));
        self::assertSame(['y', 'z'], $headers->values(ScalarKey::string('x')));

        $headers = $headers->without(ScalarKey::string('x'));
        self::assertCount(0, $headers);
        self::assertSame([], [...$headers]);
        self::assertNull($headers->get(ScalarKey::string('x')));
        self::assertCount(0, $headers->values(ScalarKey::string('x')));

        self::assertSame(Status::OK, $headers->get(Header\StatusCode::Header));

        $headers = $headers->with(Header\StatusCode::Header, Status::BadRequest);
        self::assertSame(Status::BadRequest, $headers->get(Header\StatusCode::Header));

        $headers = $headers->with(Header\MsgTtl::Header, TimeSpan::fromSeconds(2));
        self::assertEquals(TimeSpan::fromSeconds(2), $headers->get(Header\MsgTtl::Header));
        self::assertEquals(
            [
                'Nats-TTL' => ['2'],
                'Nats-Status-Code' => ['400'],
            ],
            [...$headers],
        );

        $headers = $headers->with(Header\MsgId::header(), '123');
        self::assertSame('123', $headers->get(Header\MsgId::header()));
        self::assertEquals(
            [
                'Nats-TTL' => ['2'],
                'Nats-Status-Code' => ['400'],
                'Nats-Msg-Id' => ['123'],
            ],
            [...$headers],
        );

        self::assertNull($headers->get(ScalarKey::string('x')));
    }
}
