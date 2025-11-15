<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Cancellation;
use Amp\Future;
use function Amp\async;

/**
 * @api
 */
final class Delivery
{
    /** @var ?Future<void> */
    private ?Future $replied = null;

    /** @var ?positive-int */
    private ?int $size = null;

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

        if ($this->replied !== null) {
            $this->replied->await($cancellation);

            throw new \LogicException('Message is already replied.');
        }

        ($this->replied = async($this->reply, $replyTo, $message))->await($cancellation);
    }

    /**
     * @return positive-int
     */
    public function size(): int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        $headers = [...$this->message->headers ?? new Headers()];

        /** @var positive-int $size */
        $size
            = \strlen($this->subject)
            + \strlen($this->replyTo ?? '')
            + \strlen($this->message->payload ?? '')
            + array_reduce(
                array_map(
                    static fn(string $key, array $values): int => \strlen($key) + array_reduce(
                        $values,
                        static fn(int $carry, string $value): int => \strlen($value) + $carry,
                        0,
                    ),
                    array_keys($headers),
                    array_values($headers),
                ),
                static fn(int $carry, int $size): int => $size + $carry,
                0,
            );

        return $this->size = $size;
    }
}
