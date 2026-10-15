<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue\Internal;

use Thesis\Nats\JetStream\KeyValue\Entry;

/**
 * A watch handler that keeps the entries, for the operations that read the initial values of a watch and stop it.
 *
 * @internal
 */
final class EntryCollector
{
    /** @var list<Entry> */
    public private(set) array $entries = [];

    public function __invoke(Entry $entry): void
    {
        $this->entries[] = $entry;
    }
}
