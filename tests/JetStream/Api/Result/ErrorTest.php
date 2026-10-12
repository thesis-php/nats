<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\BadApiRequest;
use Thesis\Nats\Exception\ConsumerAlreadyExists;
use Thesis\Nats\Exception\ConsumerDoesNotExist;
use Thesis\Nats\Exception\ConsumerNameAlreadyInUse;
use Thesis\Nats\Exception\ConsumerNotFound;
use Thesis\Nats\Exception\JetStreamApiException;
use Thesis\Nats\Exception\JetStreamNotEnabled;
use Thesis\Nats\Exception\JetStreamNotEnabledForAccount;
use Thesis\Nats\Exception\MaximumConsumersLimitReached;
use Thesis\Nats\Exception\MaximumStreamsLimitReached;
use Thesis\Nats\Exception\MessageNotFound;
use Thesis\Nats\Exception\MessageSizeExceedsMaximum;
use Thesis\Nats\Exception\StreamDoesNotMatch;
use Thesis\Nats\Exception\StreamNameAlreadyInUse;
use Thesis\Nats\Exception\StreamNameDoesNotMatchSubject;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\Exception\StreamSealed;
use Thesis\Nats\Exception\StreamStoreFailed;
use Thesis\Nats\Exception\UnknownJetStreamApiError;
use Thesis\Nats\Exception\WrongLastMessageId;
use Thesis\Nats\Exception\WrongLastSequence;

#[CoversClass(Error::class)]
final class ErrorTest extends TestCase
{
    /**
     * @param class-string<JetStreamApiException> $class
     */
    #[DataProvider('provideExceptionCases')]
    public function testException(int $code, int $errCode, string $class): void
    {
        $e = (new Error($code, $errCode, 'description'))->exception();

        self::assertSame($class, $e::class);
        self::assertSame($code, $e->getCode());
        self::assertSame($errCode, $e->errCode);
        self::assertSame('description', $e->description);
        self::assertSame('description', $e->getMessage());
    }

    /**
     * @return iterable<array{int, int, class-string<JetStreamApiException>}>
     */
    public static function provideExceptionCases(): iterable
    {
        yield 'bad request' => [400, 10003, BadApiRequest::class];
        yield 'consumer name already in use' => [400, 10013, ConsumerNameAlreadyInUse::class];
        yield 'consumer not found' => [404, 10014, ConsumerNotFound::class];
        yield 'maximum consumers limit' => [400, 10026, MaximumConsumersLimitReached::class];
        yield 'maximum streams limit' => [400, 10027, MaximumStreamsLimitReached::class];
        yield 'no message found' => [404, 10037, MessageNotFound::class];
        yield 'not enabled for account' => [503, 10039, JetStreamNotEnabledForAccount::class];
        yield 'message size exceeds maximum' => [400, 10054, MessageSizeExceedsMaximum::class];
        yield 'stream name in subject does not match' => [400, 10056, StreamNameDoesNotMatchSubject::class];
        yield 'stream name already in use' => [400, 10058, StreamNameAlreadyInUse::class];
        yield 'stream not found' => [404, 10059, StreamNotFound::class];
        yield 'expected stream does not match' => [400, 10060, StreamDoesNotMatch::class];
        yield 'wrong last msg id' => [400, 10070, WrongLastMessageId::class];
        yield 'wrong last sequence' => [400, 10071, WrongLastSequence::class];
        yield 'not enabled' => [503, 10076, JetStreamNotEnabled::class];
        yield 'store failed' => [503, 10077, StreamStoreFailed::class];
        yield 'consumer exists and is active' => [400, 10105, ConsumerAlreadyExists::class];
        yield 'sealed' => [400, 10109, StreamSealed::class];
        yield 'consumer already exists' => [400, 10148, ConsumerAlreadyExists::class];
        yield 'consumer does not exist' => [400, 10149, ConsumerDoesNotExist::class];
        yield 'wrong last sequence without seq' => [400, 10164, WrongLastSequence::class];
        yield 'unknown' => [500, 10074, UnknownJetStreamApiError::class];
    }
}
