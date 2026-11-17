<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use Thesis\Nats\Exception\ObjectListIsIncomplete;
use Thesis\Nats\JetStream\Delivery;
use Thesis\Nats\JetStream\Internal\IdleListener;
use Thesis\Nats\JetStream\Internal\InitialValues;
use Thesis\Nats\JetStream\ObjectStore\ObjectInfo;

/**
 * Keeps the metas a list consumer delivers until it has delivered every meta the store had when the list started.
 *
 * @internal
 */
final class InfoCollector implements IdleListener
{
    /** @var list<ObjectInfo> */
    public private(set) array $objects = [];

    private int $consumerSequence = 0;

    /** @var DeferredFuture<never> */
    private readonly DeferredFuture $lost;

    /**
     * @param \Closure(Delivery): ?ObjectInfo $decode
     */
    public function __construct(
        private readonly \Closure $decode,
        private readonly bool $showDeleted,
        private readonly InitialValues $initialValues,
    ) {
        /** @var DeferredFuture<never> $lost */
        $lost = new DeferredFuture();
        $this->lost = $lost;
        $this->lost->getFuture()->ignore();
    }

    /**
     * @throws ObjectListIsIncomplete
     */
    public function __invoke(Delivery $delivery): void
    {
        $metadata = $delivery->metadata;
        if ($metadata === null || $this->initialValues->delivered()) {
            return;
        }

        // Nothing is acknowledged, so the metas the server sent while the connection was lost are not sent again.
        if ($metadata->consumerSequence !== ++$this->consumerSequence) {
            throw new ObjectListIsIncomplete("Object metas were lost: expected delivery {$this->consumerSequence}, received {$metadata->consumerSequence}.");
        }

        $info = ($this->decode)($delivery);

        if ($info !== null && ($this->showDeleted || $info->deleted !== true)) {
            $this->objects[] = $info;
        }

        $this->initialValues->receive(max($metadata->pending, 0));
    }

    /**
     * The metas sent while the connection is lost are gone, and the server waits for the flow control reply sent to
     * the lost connection, so the list would hang: it fails at once instead.
     */
    public function disconnected(): void
    {
        if (!$this->lost->isComplete() && !$this->initialValues->delivered()) {
            $this->lost->error(new ObjectListIsIncomplete('The connection was lost before every object was received.'));
        }
    }

    /**
     * Errors once the connection is lost before every meta was received.
     *
     * @return Future<never>
     */
    public function lost(): Future
    {
        return $this->lost->getFuture();
    }

    /**
     * The server has nothing more to deliver, e.g. the metas were purged after the list had started.
     */
    public function idle(): void
    {
        $this->initialValues->complete();
    }
}
