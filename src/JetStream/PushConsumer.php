<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Description;
use Thesis\Nats\Iterator;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\Acks;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\Status;

/**
 * @api
 */
final readonly class PushConsumer
{
    private Acks $acks;

    /**
     * @param non-empty-string $name
     * @param non-empty-string $stream
     * @param non-empty-string $deliverySubject
     */
    public function __construct(
        public Api\ConsumerInfo $info,
        public string $name,
        public string $stream,
        private Client $nats,
        private string $deliverySubject,
    ) {
        $this->acks = new Acks($nats);
    }

    /**
     * @return Iterator<JetStreamDelivery>
     * @throws NatsException
     */
    public function consume(?Cancellation $cancellation = null): Iterator
    {
        return $this->nats
            ->subscribeIterator(
                subject: $this->deliverySubject,
                queueGroup: $this->info->config->deliverGroup,
                cancellation: $cancellation,
            )
            ->mapFilter(function (NatsDelivery $delivery): Iterator\Decision {
                $status = $delivery->message->headers?->statusCode();

                if ($status !== null) {
                    $description = $delivery->message->headers?->statusDescription();

                    if ($status === Status::Control && $description?->value === Description::FlowControl) {
                        $delivery->reply(new Message());
                    } elseif ($status === Status::Conflict && $description?->value === Description::ConsumerDeleted) {
                        return Iterator\Complete::Decision;
                    }
                }

                if (($replyTo = $delivery->replyTo) !== null) {
                    return new Iterator\Emit(new JetStreamDelivery(
                        message: $delivery->message,
                        subject: $delivery->subject,
                        metadata: Metadata::parse($replyTo),
                        replyTo: $replyTo,
                        acks: $this->acks,
                    ));
                }

                return Iterator\Discard::Decision;
            });
    }
}
