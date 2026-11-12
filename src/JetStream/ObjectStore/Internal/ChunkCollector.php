<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore\Internal;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Pipeline\Queue;
use Thesis\Nats\Exception\ObjectIsInvalid;
use Thesis\Nats\JetStream\Delivery;
use Thesis\Nats\JetStream\Internal\IdleListener;
use Thesis\Nats\JetStream\ObjectStore\ObjectInfo;
use Thesis\Nats\Subscription;

/**
 * Passes the chunks of an object to its reader and verifies the size and the digest once the last chunk arrives.
 *
 * @internal
 */
final class ChunkCollector implements IdleListener
{
    private readonly DigestCalculator $digest;

    private readonly DeferredCancellation $exhausted;

    /** @var non-negative-int */
    private int $size = 0;

    /** @var ?string the binary digest of the received chunks, known once the last chunk arrived */
    private ?string $checksum = null;

    /**
     * @param Queue<string> $chunks
     */
    public function __construct(
        private readonly ObjectInfo $info,
        private readonly Queue $chunks,
    ) {
        $this->digest = DigestCalculator::sha256();
        $this->exhausted = new DeferredCancellation();
    }

    /**
     * Cancelled when the server has nothing more to deliver before the last chunk: the chunks are gone.
     */
    public function exhausted(): Cancellation
    {
        return $this->exhausted->getCancellation();
    }

    public function idle(): void
    {
        if ($this->checksum === null) {
            $this->exhausted->cancel();
        }
    }

    public function __invoke(Delivery $delivery, Subscription $subscription): void
    {
        if ($this->checksum !== null) {
            return;
        }

        // The reader dropped the object before reading it to the end: nobody needs the rest.
        if ($this->chunks->isDisposed()) {
            $subscription->stop();

            return;
        }

        $payload = $delivery->message->payload ?? '';

        if ($payload !== '') {
            $this->digest->update($payload);
            $this->size += \strlen($payload);

            $pushed = $this->chunks->pushAsync($payload);
            $pushed->ignore();
        }

        if ($delivery->metadata?->pending === 0) {
            $this->checksum = $this->digest->finish();
            $subscription->stop();
        }
    }

    /**
     * @throws ObjectIsInvalid
     */
    public function verify(): void
    {
        if ($this->checksum === null) {
            throw new ObjectIsInvalid("Object '{$this->info->name}' is missing chunks: received {$this->size} of {$this->info->size} bytes.");
        }

        if ($this->size !== $this->info->size) {
            throw new ObjectIsInvalid("Object '{$this->info->name}' size mismatch: expected {$this->info->size} bytes, received {$this->size}.");
        }

        if (!hash_equals(DigestCalculator::decode($this->info->digest ?? ''), $this->checksum)) {
            throw new ObjectIsInvalid("Object '{$this->info->name}' digest mismatch.");
        }
    }
}
