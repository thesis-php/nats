<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Amp\Pipeline;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Description;
use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\Header\StatusDescription;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Metadata;
use Thesis\Nats\Message;
use Thesis\Nats\Status;

/**
 * @internal
 */
final readonly class PushMessageHandler
{
    private Acks $acks;

    /**
     * @param Pipeline\Queue<JetStreamDelivery> $queue
     */
    public function __construct(
        Client $nc,
        private Pipeline\Queue $queue,
    ) {
        $this->acks = new Acks($nc);
    }

    /**
     * @param non-empty-string $sid
     */
    public function __invoke(
        NatsDelivery $delivery,
        Client $nc,
        string $sid,
    ): void {
        $status = $delivery->message->headers?->get(StatusCode::Header);

        if ($status !== null) {
            $description = Description::tryFrom(strtolower($delivery->message->headers?->get(StatusDescription::header()) ?? '')) ?? Description::Unknown;

            if ($status === Status::Control && $description === Description::FlowControl) {
                $delivery->reply(new Message());
            } elseif ($status === Status::Conflict && $description === Description::ConsumerDeleted) {
                $this->queue->complete();
                $nc->unsubscribe($sid);
            }

            return;
        }

        if (($replyTo = $delivery->replyTo) !== null) {
            $this->queue->push(
                new JetStreamDelivery(
                    message: $delivery->message,
                    subject: $delivery->subject,
                    metadata: Metadata::parse($replyTo),
                    replyTo: $replyTo,
                    acks: $this->acks,
                ),
            );
        }
    }
}
