<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Cancellation;
use Thesis\Sync;
use function Amp\weakClosure;

/**
 * @api
 */
final class Delivery
{
    /** @var ?Sync\Once<bool> */
    private ?Sync\Once $replied = null;

    /**
     * @param \Closure(non-empty-string, Message): void $reply
     * @param non-empty-string $subject
     * @param ?non-empty-string $replyTo
     */
    public function __construct(
        private readonly \Closure $reply,
        public readonly string $subject,
        public readonly ?string $replyTo = null,
        public readonly Message $message = new Message(),
    ) {}

    public function reply(Message $message, ?Cancellation $cancellation = null): void
    {
        $replyTo = $this->replyTo;
        if ($replyTo === null) {
            throw new \LogicException('Message is not a request.');
        }

        if ($this->replied?->await($cancellation)) {
            throw new \LogicException('Message is already replied.');
        }

        $this->replied ??= new Sync\Once(weakClosure(function () use ($message, $replyTo): bool {
            ($this->reply)($replyTo, $message);

            return true;
        }));

        $this->replied->await($cancellation);
    }
}
