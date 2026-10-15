<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
final readonly class Description implements \Stringable
{
    public const string FlowControl = 'flowcontrol request';
    public const string IdleHeartbeat = 'idle heartbeat';
    public const string ConsumerDeleted = 'consumer deleted';
    public const string LeadershipChange = 'leadership change';
    public const string MaxBytesExceeded = 'message size exceeds maxbytes';
    public const string BatchCompleted = 'batch completed';
    public const string ServerShutdown = 'server shutdown';
    public const string NoMessages = 'no messages';
    public const string NoResults = 'no results';
    public const string RequestTimeout = 'request timeout';
    public const string Eob = 'eob';
    public const string ConsumerIsPushBased = 'consumer is push based';
    public const string MaxWaitingExceeded = 'exceeded maxwaiting';
    public const string MaxRequestBatchExceeded = 'exceeded maxrequestbatch';
    public const string MaxRequestExpiresExceeded = 'exceeded maxrequestexpires';
    public const string MaxRequestMaxBytesExceeded = 'exceeded maxrequestmaxbytes';
    public const string OK = 'OK';

    /**
     * @param non-empty-string $value
     */
    public function __construct(
        public string $value,
    ) {}

    /**
     * @param self::* ...$values
     */
    public function is(string ...$values): bool
    {
        return \in_array($this->value, $values, true);
    }

    /**
     * Some descriptions carry a variable suffix, e.g. "exceeded maxrequestbatch of 10".
     *
     * @param self::* ...$prefixes
     */
    public function startsWith(string ...$prefixes): bool
    {
        return array_any($prefixes, fn(string $prefix): bool => str_starts_with($this->value, $prefix));
    }

    /**
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
