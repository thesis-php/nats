<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Exception\PublishBatchAlreadyCommitted;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\JetStream\Api\AckResponse;

/**
 * @api
 */
final class PublishBatch
{
    /** @var non-empty-string */
    private readonly string $id;

    /** @var non-negative-int */
    private int $count = 0;

    private bool $committed = false;

    public function __construct(
        private readonly JetStream $js,
        private readonly Client $nc,
    ) {
        $this->id = Id\generateUniqueId(30);
    }

    /**
     * @param non-empty-string $subject
     * @throws NatsException
     */
    public function publish(
        string $subject,
        Message $message,
        PublishBatchOptions $publishBatchOptions = new PublishBatchOptions(),
    ): void {
        if ($this->committed) {
            throw new PublishBatchAlreadyCommitted();
        }

        $headers = ($message->headers ?? new Headers())
            ->with(Header\BatchId::header(), $this->id)
            ->with(Header\BatchSequence::header(), ++$this->count);

        if ($publishBatchOptions->commit) {
            $headers = $headers
                ->with(Header\BatchCommit::header(), 1);
        }

        $publish = match (true) {
            $publishBatchOptions->ack || $publishBatchOptions->commit => $this->js->publish(...),
            default => $this->nc->publish(...),
        };

        $ack = $publish($subject, new Message(
            payload: $message->payload,
            headers: $headers,
        ));

        if ($publishBatchOptions->commit) {
            $this->committed = true;

            if (!$ack instanceof AckResponse) {
                throw new \LogicException('No publish ack received');
            }

            if ($ack->count !== $this->count) {
                throw new \UnexpectedValueException("Batch didn't contain number of published messages");
            }
        }
    }
}
