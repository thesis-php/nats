<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Rpc;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Message;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;

/**
 * @internal
 * @phpstan-import-type Subscribe from Client
 */
final class Handler
{
    /** @var array<non-empty-string, callable(Delivery): void> */
    private array $futures = [];

    /** @var non-empty-string */
    private readonly string $inboxId;

    private ?Subscription $subscription = null;

    public function __construct()
    {
        $inboxId = Id\generateInboxId();
        $this->inboxId = "{$inboxId}.";
    }

    /**
     * @param Subscribe $subscribe
     */
    public function setup(\Closure $subscribe): void
    {
        $futures = &$this->futures;
        $inboxId = $this->inboxId;

        $this->subscription = $subscribe(
            "{$inboxId}*",
            static function (Delivery $delivery) use (
                &$futures,
                $inboxId,
            ): void {
                $replyTo = ReplyTo::parse($inboxId, $delivery->subject);

                try {
                    ($futures[$replyTo->token] ?? static fn() => null)($delivery);
                } finally {
                    unset($futures[$replyTo->token]);
                }
            },
        );
    }

    public function shutdown(?Cancellation $cancellation = null): void
    {
        try {
            $this->subscription?->stop($cancellation);
        } finally {
            $this->subscription = null;
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
        Client $client,
    ): Future {
        $replyTo = ReplyTo::new($this->inboxId);

        /** @var DeferredFuture<Delivery> $deferred */
        $deferred = new DeferredFuture();
        $this->futures[$replyTo->token] = static function (Delivery $delivery) use ($deferred): void {
            if ($delivery->message->headers?->get(StatusCode::Header) === Status::NoResponders) {
                $deferred->error(new RequestHasNoResponders());
            } else {
                $deferred->complete($delivery);
            }
        };

        $client->publish($subject, $message, $replyTo->subject);

        return $deferred->getFuture();
    }
}
