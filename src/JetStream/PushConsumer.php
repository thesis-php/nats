<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Description;
use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\Header\StatusDescription;
use Thesis\Nats\Iterator;
use Thesis\Nats\JetStream\Internal\Acks;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
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
     * @return Iterator<Delivery>
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
            ->mapFilter($this->filterDelivery(...))
            ;
    }

    private function filterDelivery(NatsDelivery $delivery): false|JetStreamDelivery
    {
        $status = $delivery->message->headers?->get(StatusCode::Header);

        if ($status !== null) {
            $description = Description::tryFrom(strtolower($delivery->message->headers?->get(StatusDescription::header()) ?? '')) ?? Description::Unknown;

            if ($status === Status::Control && $description === Description::FlowControl) {
                $delivery->reply(new Message());
            } elseif ($status === Status::Conflict && $description === Description::ConsumerDeleted) {
                // TODO: stop consumer
            }
        } else if (($replyTo = $delivery->replyTo) !== null) {
            return new JetStreamDelivery(
                message: $delivery->message,
                subject: $delivery->subject,
                metadata: Metadata::parse($replyTo),
                replyTo: $replyTo,
                acks: $this->acks,
            );
        }

        return false;
    }
}
