<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final readonly class KvOperation
{
    private const string HEADER = 'KV-Operation';
    public const string OP_DEL = 'DEL';
    public const string OP_PURGE = 'PURGE';
    public const string OP_PUT = 'PUT';

    /**
     * @return ScalarKey<self::OP_*>
     */
    public static function header(): ScalarKey
    {
        /** @var ScalarKey<self::OP_*> */
        return ScalarKey::string(self::HEADER);
    }
}
