<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\Headers;

#[CoversFunction('\Thesis\Nats\Internal\Protocol\encodeHeaders')]
#[CoversFunction('\Thesis\Nats\Internal\Protocol\decodeHeaders')]
final class HeadersTest extends TestCase
{
    /**
     * @param non-empty-string $encoded
     */
    #[TestWith([
        new Headers(['Bar' => ['Baz']]),
        "NATS/1.0\r\nBar: Baz\r\n\r\n",
    ])]
    #[TestWith([
        new Headers(['Bar' => ['Baz', 'Foo']]),
        "NATS/1.0\r\nBar: Baz\r\nBar: Foo\r\n\r\n",
    ])]
    #[TestWith([
        new Headers(['Attempts' => ['1']]),
        "NATS/1.0\r\nAttempts: 1\r\n\r\n",
    ])]
    #[TestWith([
        new Headers([StatusCode::Header->value => ['503']]),
        "NATS/1.0 503\r\n\r\n",
    ])]
    #[TestWith([
        new Headers(['X' => ['Y'], StatusCode::Header->value => ['200']]),
        "NATS/1.0 200\r\nX: Y\r\n\r\n",
    ])]
    public function testEncode(Headers $headers, string $encoded): void
    {
        self::assertEquals($encoded, encodeHeaders($headers));
        self::assertEquals($headers, decodeHeaders($encoded));
    }
}
