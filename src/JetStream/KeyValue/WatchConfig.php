<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Thesis\Nats\Internal\Validation\Validate;

/**
 * By default a watch first delivers the latest entry of every watched key (deletes included),
 * {@see Watcher::awaitInitialValues()} returns once they all have been handled, and then the watch delivers the updates.
 *
 * @api
 */
final readonly class WatchConfig
{
    /**
     * @param bool $headersOnly deprecated, use $metaOnly
     * @param bool $ignoreDeletes do not pass delete and purge markers to the handler
     * @param bool $includeHistory deliver every stored revision of the watched keys, not only the latest ones
     * @param bool $updatesOnly deliver only the changes made after the watch has started, without the current values
     * @param bool $metaOnly deliver entries without values, {@see Entry::$size} still has the size of the value
     * @param ?positive-int $resumeFromRevision deliver every revision starting from this one, e.g. to continue a watch that was stopped
     * @throws \InvalidArgumentException
     */
    public function __construct(
        /** @deprecated use {@see WatchConfig::$metaOnly} */
        public bool $headersOnly = false,
        public bool $ignoreDeletes = false,
        public bool $includeHistory = false,
        public bool $updatesOnly = false,
        public bool $metaOnly = false,
        public ?int $resumeFromRevision = null,
    ) {
        if ($this->includeHistory && $this->updatesOnly) {
            throw new \InvalidArgumentException('Include history can not be used with updates only.');
        }

        if ($this->updatesOnly && $this->resumeFromRevision !== null) {
            throw new \InvalidArgumentException('Resume from revision can not be used with updates only.');
        }

        if ($this->resumeFromRevision !== null) {
            Validate::count($this->resumeFromRevision, 'revision to resume from');
        }
    }
}
