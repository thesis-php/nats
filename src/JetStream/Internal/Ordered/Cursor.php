<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal\Ordered;

use Thesis\Nats\JetStream\Metadata;

/**
 * Where an ordered consumer is: the last message delivered and the current server consumer. It outlives a subscription,
 * so the next consume() continues after the last message.
 *
 * @internal
 */
final class Cursor
{
    /** @var non-negative-int the stream sequence of the last message delivered, 0 before the first one */
    public private(set) int $streamSequence = 0;

    /** @var non-negative-int the consumer sequence of the last message the current server consumer delivered */
    public private(set) int $consumerSequence = 0;

    /** @var non-negative-int the number of the current server consumer */
    public private(set) int $generation = 0;

    /**
     * Whether the message is the one right after the last one, i.e. nothing is lost in between.
     */
    public function follows(Metadata $metadata): bool
    {
        return $metadata->consumerSequence === $this->consumerSequence + 1;
    }

    public function advance(Metadata $metadata): void
    {
        $this->consumerSequence = max($metadata->consumerSequence, 0);
        $this->streamSequence = max($metadata->streamSequence, 0);
    }

    /**
     * A new server consumer starts its sequence anew.
     *
     * @return positive-int the number of the new consumer
     */
    public function nextGeneration(): int
    {
        $this->consumerSequence = 0;

        return ++$this->generation;
    }
}
