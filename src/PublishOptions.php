<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Internal\Validation\Validate;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class PublishOptions
{
    private const int DEFAULT_RETRY_ATTEMPTS = 2;
    private const int DEFAULT_RETRY_WAIT_MILLIS = 250;

    /** @var non-negative-int */
    public int $retryAttempts;

    public TimeSpan $retryWait;

    /**
     * @param ?non-empty-string $msgId used for deduplication within the stream duplicate window
     * @param ?non-empty-string $expectedStream the server rejects the message if it is stored by another stream
     * @param ?non-negative-int $expectedLastSequence the server rejects the message if the last stream sequence differs
     * @param ?non-negative-int $expectedLastSubjectSequence the server rejects the message if the last sequence of the subject differs
     * @param ?non-empty-string $expectedLastSubjectSequenceSubject the subject whose last sequence is checked instead of the published one
     * @param ?non-empty-string $expectedLastMsgId the server rejects the message if the id of the last stream message differs
     * @param ?TimeSpan $ttl per-message TTL, at least one second or {@see TimeSpan::fromSeconds(-1)} to never expire; requires {@see JetStream\Api\StreamConfig::$allowMessageTtl}
     * @param ?non-negative-int $retryAttempts how many times to retry the publish if there are no responders, e.g. while the stream leader is being elected
     * @param ?TimeSpan $retryWait the delay between retries
     */
    public function __construct(
        public ?string $msgId = null,
        public ?string $expectedStream = null,
        public ?int $expectedLastSequence = null,
        public ?int $expectedLastSubjectSequence = null,
        public ?string $expectedLastSubjectSequenceSubject = null,
        public ?string $expectedLastMsgId = null,
        public ?TimeSpan $ttl = null,
        ?int $retryAttempts = null,
        ?TimeSpan $retryWait = null,
    ) {
        if ($this->expectedStream !== null) {
            Validate::name($this->expectedStream);
        }

        if ($this->expectedLastSubjectSequenceSubject !== null) {
            if ($this->expectedLastSubjectSequence === null) {
                throw new \InvalidArgumentException('Expected last subject sequence subject requires the expected last subject sequence.');
            }

            Validate::subscribeSubject($this->expectedLastSubjectSequenceSubject);
        }

        if ($this->ttl !== null
            && !$this->ttl->isEqualTo(TimeSpan::fromSeconds(-1))
            && $this->ttl->isLessThan(TimeSpan::fromSeconds(1))
        ) {
            throw new \InvalidArgumentException('Message ttl must be at least one second or -1 second to never expire.');
        }

        $this->retryAttempts = $retryAttempts ?? self::DEFAULT_RETRY_ATTEMPTS;

        $retryWait ??= TimeSpan::fromMilliseconds(self::DEFAULT_RETRY_WAIT_MILLIS);

        if ($retryWait->isNegativeOrZero()) {
            throw new \InvalidArgumentException('Retry wait must be positive.');
        }

        $this->retryWait = $retryWait;
    }

    /**
     * @internal
     */
    public function apply(?Headers $headers): ?Headers
    {
        $headers = self::set($headers, Header\MsgId::header(), $this->msgId);
        $headers = self::set($headers, Header\ExpectedStream::header(), $this->expectedStream);
        $headers = self::set($headers, Header\ExpectedLastSeq::Header, $this->expectedLastSequence);
        $headers = self::set($headers, Header\ExpectedLastSubjSeq::Header, $this->expectedLastSubjectSequence);
        $headers = self::set($headers, Header\ExpectedLastSubjSeqSubject::header(), $this->expectedLastSubjectSequenceSubject);
        $headers = self::set($headers, Header\ExpectedLastMsgID::header(), $this->expectedLastMsgId);

        return self::set($headers, Header\MsgTtl::Header, $this->ttl);
    }

    /**
     * Replaces the header set on the message by hand, as the option is the more explicit one.
     *
     * @template T
     * @param HeaderKey<T> $key
     * @param ?T $value
     */
    private static function set(?Headers $headers, HeaderKey $key, mixed $value): ?Headers
    {
        if ($value === null) {
            return $headers;
        }

        return ($headers ?? new Headers())
            ->without($key)
            ->with($key, $value);
    }
}
