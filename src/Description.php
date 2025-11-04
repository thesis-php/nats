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
    public const string OK = 'OK';

    /**
     * @param non-empty-string $value
     */
    public function __construct(
        public string $value,
    ) {}

    /**
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
