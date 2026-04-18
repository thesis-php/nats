<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Rpc;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Exception\MessageNotFound;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Message;
use Thesis\Nats\Status;

/**
 * @internal
 */
final class Handler
{
    /** @var array<non-empty-string, PendingRequest> */
    private array $pendings = [];

    /** @var non-empty-string */
    private readonly string $inboxId;

    /** @var ?\Closure(?Cancellation=): void */
    private ?\Closure $unsubscribe = null;

    public function __construct()
    {
        $inboxId = Id\generateInboxId();
        $this->inboxId = "{$inboxId}.";
    }

    /**
     * @param \Closure(non-empty-string, callable(Delivery): void, ?Cancellation=): (\Closure(): void) $subscribe
     */
    public function setup(\Closure $subscribe, ?Cancellation $cancellation = null): void
    {
        $pendings = &$this->pendings;
        $inboxId = $this->inboxId;

        $this->unsubscribe = $subscribe(
            "{$inboxId}*",
            static function (Delivery $delivery) use (
                &$pendings,
                $inboxId,
            ): void {
                $replyTo = ReplyTo::parse($inboxId, $delivery->subject);

                try {
                    $pending = $pendings[$replyTo->token] ?? null;
                    if ($pending !== null) {
                        $pending($delivery);
                    }
                } finally {
                    unset($pendings[$replyTo->token]);
                }
            },
            $cancellation,
        );
    }

    public function shutdown(?Cancellation $cancellation = null): void
    {
        [$pendings, $this->pendings] = [$this->pendings, []];

        $e = new ConnectionWasClosed();

        foreach ($pendings as $pending) {
            $pending->deferred->error($e);
        }

        [$unsubscribe, $this->unsubscribe] = [$this->unsubscribe, null];
        if ($unsubscribe !== null) {
            $unsubscribe($cancellation);
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
        $this->pendings[$replyTo->token] = new PendingRequest(
            handle: static function (Delivery $delivery) use ($deferred): void {
                $status = $delivery->message->headers?->statusCode();
                if ($status === Status::NoResponders) {
                    $deferred->error(new RequestHasNoResponders());
                } elseif ($status === Status::NoMessages) {
                    $deferred->error(new MessageNotFound());
                } else {
                    $deferred->complete($delivery);
                }
            },
            deferred: $deferred,
        );

        $client->publish($subject, $message, $replyTo->subject);

        return $deferred->getFuture();
    }
}
