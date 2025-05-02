<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Rpc;

use Amp\DeferredFuture;
use Amp\Future;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Message;
use Thesis\Nats\Status;

/**
 * @internal
 */
final class Handler
{
    /** @var array<non-empty-string, callable(Delivery): void> */
    private array $futures = [];

    /** @var non-empty-string */
    private readonly string $inboxId;

    /** @var ?non-empty-string */
    private ?string $subscriptionId = null;

    public function __construct(private readonly Client $client)
    {
        $inboxId = Id\generateInboxId();
        $this->inboxId = "{$inboxId}.";
    }

    public function setup(): void
    {
        $this->subscriptionId = $this->client->subscribe(
            "{$this->inboxId}*",
            function (Delivery $delivery): void {
                $replyTo = ReplyTo::parse($this->inboxId, $delivery->subject);

                try {
                    ($this->futures[$replyTo->token] ?? static fn() => null)($delivery);
                } finally {
                    unset($this->futures[$replyTo->token]);
                }
            },
        );
    }

    public function shutdown(): void
    {
        if ($this->subscriptionId === null) {
            return;
        }

        try {
            $this->client->unsubscribe($this->subscriptionId);
        } finally {
            $this->subscriptionId = null;
            $this->futures = [];
        }
    }

    /**
     * @param non-empty-string $subject
     * @return Future<Delivery>
     */
    public function request(
        string $subject,
        Message $message,
    ): Future {
        $replyTo = ReplyTo::new($this->inboxId);

        /** @var DeferredFuture<Delivery> $deferred */
        $deferred = new DeferredFuture();
        $this->futures[$replyTo->token] = static function (Delivery $delivery) use ($deferred): void {
            if ($delivery->message->status === Status::NoResponders) {
                $deferred->error(new RequestHasNoResponders());
            } else {
                $deferred->complete($delivery);
            }
        };

        $this->client->publish($subject, $message, $replyTo->subject);

        return $deferred->getFuture();
    }
}
