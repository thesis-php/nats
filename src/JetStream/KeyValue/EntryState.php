<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Thesis\Nats\Header;

/**
 * @api
 */
enum EntryState
{
    case Created;
    case Deleted;
    case Purged;

    /**
     * @internal
     * @param ?Header\KvOperation::OP_* $op
     */
    public static function fromKVOperation(?string $op): self
    {
        return match ($op) {
            Header\KvOperation::OP_DEL => EntryState::Deleted,
            Header\KvOperation::OP_PURGE => EntryState::Purged,
            default => EntryState::Created,
        };
    }
}
