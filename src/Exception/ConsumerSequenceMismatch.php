<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\Client;
use Thesis\Nats\NatsException;

/**
 * A push consumer has delivered messages the subscription never received, e.g. dropped as a slow consumer:
 * the idle heartbeat names a later consumer sequence than the last one received. Reported to {@see Client::onError()},
 * the subscription keeps going; the lost messages can be read again starting at {@see $streamResumeSequence}.
 *
 * @api
 */
final class ConsumerSequenceMismatch extends NatsException
{
    /**
     * @param non-negative-int $consumerSequence the consumer sequence of the last message received
     * @param non-negative-int $lastConsumerSequence the consumer sequence of the last message the server delivered
     * @param positive-int $streamResumeSequence the stream sequence right after the last message received
     */
    public function __construct(
        public readonly string $stream,
        public readonly string $consumer,
        public readonly int $consumerSequence,
        public readonly int $lastConsumerSequence,
        public readonly int $streamResumeSequence,
    ) {
        parent::__construct(\sprintf(
            'Consumer "%s" of the stream "%s" delivered up to the consumer sequence %d, the last one received is %d; resume from the stream sequence %d.',
            $consumer,
            $stream,
            $lastConsumerSequence,
            $consumerSequence,
            $streamResumeSequence,
        ));
    }
}
