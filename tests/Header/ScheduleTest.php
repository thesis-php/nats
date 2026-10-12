<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Schedule::class)]
final class ScheduleTest extends TestCase
{
    public function testEncodeDecodeRoundtrip(): void
    {
        $time = new \DateTimeImmutable('2026-10-12T10:20:30+03:00');

        $encoded = Schedule::Header->encode($time);
        self::assertSame('@at 2026-10-12T10:20:30+03:00', $encoded);

        $decoded = Schedule::Header->decode($encoded);
        self::assertInstanceOf(\DateTimeImmutable::class, $decoded);
        self::assertSame($time->getTimestamp(), $decoded->getTimestamp());
        self::assertSame($time->format(\DateTimeInterface::RFC3339), $decoded->format(\DateTimeInterface::RFC3339));
    }

    #[DataProvider('provideDecodeTimeCases')]
    public function testDecodeTime(string $value, string $expected): void
    {
        $decoded = Schedule::Header->decode($value);

        self::assertInstanceOf(\DateTimeImmutable::class, $decoded);
        self::assertSame($expected, $decoded->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDecodeTimeCases(): iterable
    {
        yield 'utc' => ['@at 2026-10-12T10:20:30Z', '2026-10-12T10:20:30+00:00'];
        yield 'offset' => ['@at 2026-10-12T10:20:30-05:00', '2026-10-12T15:20:30+00:00'];
        yield 'nanoseconds' => ['@at 2026-10-12T10:20:30.123456789Z', '2026-10-12T10:20:30+00:00'];
        yield 'milliseconds' => ['@at 2026-10-12T10:20:30.123+02:00', '2026-10-12T08:20:30+00:00'];
        yield 'extra spaces' => ['@at   2026-10-12T10:20:30Z  ', '2026-10-12T10:20:30+00:00'];
    }

    public function testDecodeNanosecondsKeepsMicroseconds(): void
    {
        $decoded = Schedule::Header->decode('@at 2026-10-12T10:20:30.123456789Z');

        self::assertInstanceOf(\DateTimeImmutable::class, $decoded);
        self::assertSame('123456', $decoded->format('u'));
    }

    public function testCronPassthrough(): void
    {
        self::assertSame('@every 1m', Schedule::Header->encode('@every 1m'));
        self::assertSame('@every 1m', Schedule::Header->decode('@every 1m'));
        self::assertSame('*/5 * * * * *', Schedule::Header->decode('*/5 * * * * *'));
    }

    #[DataProvider('provideDecodeInvalidCases')]
    public function testDecodeInvalid(string $value): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Schedule::Header->decode($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDecodeInvalidCases(): iterable
    {
        yield 'empty' => [''];
        yield 'at without time' => ['@at '];
        yield 'at with garbage' => ['@at not-a-date'];
    }
}
