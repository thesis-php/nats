<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final readonly class BatchCommit
{
    private const string HEADER = 'Nats-Batch-Commit';

    /**
     * @return ScalarKey<1>
     */
    public static function header(): ScalarKey
    {
        /** @var ScalarKey<1> */
        return ScalarKey::int(self::HEADER);
    }
}
