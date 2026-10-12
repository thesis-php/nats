<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;
use Thesis\Nats\Header\ExpectedLastMsgID;
use Thesis\Nats\Header\ExpectedLastSeq;
use Thesis\Nats\Header\ExpectedLastSubjSeq;
use Thesis\Nats\Header\ExpectedLastSubjSeqSubject;
use Thesis\Nats\Header\ExpectedStream;
use Thesis\Nats\Header\MsgId;
use Thesis\Nats\Header\MsgTtl;
use Thesis\Nats\Header\ScalarKey;
use Thesis\Time\TimeSpan;

#[CoversClass(PublishOptions::class)]
final class PublishOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new PublishOptions();

        self::assertSame(2, $options->retryAttempts);
        self::assertSame(250, $options->retryWait->toMilliseconds());
        self::assertNull($options->apply(null));
    }

    public function testApplySetsHeaders(): void
    {
        $headers = (new PublishOptions(
            msgId: 'id-1',
            expectedStream: 'orders',
            expectedLastSequence: 10,
            expectedLastSubjectSequence: 5,
            expectedLastSubjectSequenceSubject: 'orders.*',
            expectedLastMsgId: 'id-0',
            ttl: TimeSpan::fromMinutes(1),
        ))->apply(null);

        self::assertNotNull($headers);
        self::assertSame('id-1', $headers->get(MsgId::header()));
        self::assertSame('orders', $headers->get(ExpectedStream::header()));
        self::assertSame(10, $headers->get(ExpectedLastSeq::Header));
        self::assertSame(5, $headers->get(ExpectedLastSubjSeq::Header));
        self::assertSame('orders.*', $headers->get(ExpectedLastSubjSeqSubject::header()));
        self::assertSame('id-0', $headers->get(ExpectedLastMsgID::header()));
        self::assertSame(60, $headers->get(MsgTtl::Header)?->toSeconds());
    }

    public function testApplyReplacesHeadersAndKeepsOthers(): void
    {
        $custom = ScalarKey::string('X-Custom');

        $headers = (new PublishOptions(msgId: 'from-options'))->apply(
            (new Headers())
                ->with(MsgId::header(), 'by-hand')
                ->with($custom, 'value'),
        );

        self::assertNotNull($headers);
        self::assertSame(['from-options'], $headers->values(MsgId::header()));
        self::assertSame('value', $headers->get($custom));
    }

    public function testNeverExpiringTtl(): void
    {
        $headers = (new PublishOptions(ttl: TimeSpan::fromSeconds(-1)))->apply(null);

        self::assertNotNull($headers);
        self::assertSame(-1, $headers->get(MsgTtl::Header)?->toSeconds());
    }

    /**
     * @param \Closure(): PublishOptions $options
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('provideInvalidOptionsCases')]
    public function testInvalidOptions(\Closure $options, string $exception): void
    {
        self::expectException($exception);

        $options();
    }

    /**
     * @return iterable<string, array{\Closure(): PublishOptions, class-string<\Throwable>}>
     */
    public static function provideInvalidOptionsCases(): iterable
    {
        yield 'invalid expected stream' => [
            static fn(): PublishOptions => new PublishOptions(expectedStream: 'orders.new'),
            InvalidName::class,
        ];

        yield 'subject without the subject sequence' => [
            static fn(): PublishOptions => new PublishOptions(expectedLastSubjectSequenceSubject: 'orders.*'),
            \InvalidArgumentException::class,
        ];

        yield 'invalid subject of the subject sequence' => [
            static fn(): PublishOptions => new PublishOptions(
                expectedLastSubjectSequence: 1,
                expectedLastSubjectSequenceSubject: 'orders..new',
            ),
            InvalidSubject::class,
        ];

        yield 'sub-second ttl' => [
            static fn(): PublishOptions => new PublishOptions(ttl: TimeSpan::fromMilliseconds(500)),
            \InvalidArgumentException::class,
        ];

        yield 'zero ttl' => [
            static fn(): PublishOptions => new PublishOptions(ttl: TimeSpan::fromSeconds(0)),
            \InvalidArgumentException::class,
        ];

        yield 'zero retry wait' => [
            static fn(): PublishOptions => new PublishOptions(retryWait: TimeSpan::fromSeconds(0)),
            \InvalidArgumentException::class,
        ];
    }
}
