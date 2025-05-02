<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Future;
use function Amp\async;

/**
 * @api
 */
final class Delivery
{
    /** @var ?Future<void> */
    private ?Future $repliedFuture = null;

    private bool $replied = false;

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

    public function reply(Message $message): void
    {
        $this->repliedFuture?->await();

        if ($this->replied) {
            throw new \LogicException('Message is already replied.');
        }

        $replyTo = $this->replyTo;
        if ($replyTo === null) {
            throw new \LogicException('Message is not a request.');
        }

        /** @phpstan-ignore argument.type */
        $this->repliedFuture ??= async($this->reply, $replyTo, $message);

        try {
            $this->repliedFuture->await();
            $this->replied = true;
        } finally {
            $this->repliedFuture = null;
        }
    }
}
