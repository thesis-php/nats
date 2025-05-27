<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Result;

use Thesis\Nats\Exception\ConsumerDoesNotExist;
use Thesis\Nats\Exception\ConsumerNotFound;
use Thesis\Nats\Exception\StreamDoesNotMatch;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\Exception\WrongLastMessageId;
use Thesis\Nats\Exception\WrongLastSequence;

/**
 * @internal
 */
final readonly class Error
{
    private const int STREAM_NOT_FOUND = 10059;
    private const int CONSUMER_NOT_FOUND = 10014;
    private const int CONSUMER_DOES_NOT_EXISTS = 10149;
    private const int STREAM_DOES_NOT_MATCH = 10060;
    private const int WRONG_LAST_MESSAGE_ID = 10070;
    private const int WRONG_LAST_SEQUENCE = 10071;

    public function __construct(
        public int $code,
        public int $errCode,
        public string $description,
    ) {}

    public function exception(): \Exception
    {
        return match ($this->errCode) {
            self::STREAM_NOT_FOUND => new StreamNotFound($this->description),
            self::CONSUMER_NOT_FOUND => new ConsumerNotFound($this->description),
            self::CONSUMER_DOES_NOT_EXISTS => new ConsumerDoesNotExist($this->description),
            self::STREAM_DOES_NOT_MATCH => new StreamDoesNotMatch($this->description),
            self::WRONG_LAST_MESSAGE_ID => new WrongLastMessageId($this->description),
            self::WRONG_LAST_SEQUENCE => new WrongLastSequence($this->description),
            default => new \RuntimeException("Nats error: '{$this->description} ({$this->errCode}) received'."),
        };
    }
}
