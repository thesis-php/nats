<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Thesis\Nats\Header;
use Thesis\Nats\Headers;

/**
 * @api
 */
enum EntryState
{
    case Created;
    case Deleted;
    case Purged;

    /**
     * The operation is taken from the KV-Operation header of {@see Bucket::delete()} and {@see Bucket::purge()},
     * or from the Nats-Marker-Reason header of a marker the server places itself when a key expires or is removed
     * (see {@see BucketConfig::$limitMarkerTtl}).
     *
     * @internal
     */
    public static function fromHeaders(?Headers $headers): self
    {
        if ($headers === null) {
            return self::Created;
        }

        $op = $headers->get(Header\KvOperation::header());

        if ($op !== null) {
            return match ($op) {
                Header\KvOperation::OP_DEL => self::Deleted,
                Header\KvOperation::OP_PURGE => self::Purged,
                default => self::Created,
            };
        }

        return match ($headers->get(Header\MarkerReason::header())) {
            'MaxAge', 'Purge' => self::Purged,
            'Remove' => self::Deleted,
            default => self::Created,
        };
    }

    public function isMarker(): bool
    {
        return $this !== self::Created;
    }
}
