<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Result;

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

/**
 * @internal
 */
final readonly class Error
{
    /**
     * Codes are taken from https://github.com/nats-io/nats-server/blob/main/server/errors.json.
     *
     * @var array<int, class-string<JetStreamApiException>>
     */
    private const array EXCEPTIONS = [
        10003 => BadApiRequest::class,
        10013 => ConsumerNameAlreadyInUse::class,
        10014 => ConsumerNotFound::class,
        10026 => MaximumConsumersLimitReached::class,
        10027 => MaximumStreamsLimitReached::class,
        10037 => MessageNotFound::class,
        10039 => JetStreamNotEnabledForAccount::class,
        10054 => MessageSizeExceedsMaximum::class,
        10056 => StreamNameDoesNotMatchSubject::class,
        10058 => StreamNameAlreadyInUse::class,
        10059 => StreamNotFound::class,
        10060 => StreamDoesNotMatch::class,
        10070 => WrongLastMessageId::class,
        10071 => WrongLastSequence::class,
        10076 => JetStreamNotEnabled::class,
        10077 => StreamStoreFailed::class,
        10105 => ConsumerAlreadyExists::class, // consumer already exists and is still active
        10109 => StreamSealed::class,
        10148 => ConsumerAlreadyExists::class,
        10149 => ConsumerDoesNotExist::class,
        10164 => WrongLastSequence::class, // the same as 10071, but without the sequence in the description
    ];

    public function __construct(
        public int $code,
        public int $errCode,
        public string $description,
    ) {}

    public function exception(): JetStreamApiException
    {
        $exception = self::EXCEPTIONS[$this->errCode] ?? UnknownJetStreamApiError::class;

        return new $exception($this->code, $this->errCode, $this->description);
    }
}
