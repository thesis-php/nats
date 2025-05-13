<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements Request<AccountInfo>
 */
final readonly class GetAccountInfoRequest implements Request
{
    public function endpoint(): string
    {
        return 'INFO';
    }

    public function payload(): null
    {
        return null;
    }

    public function type(): string
    {
        return AccountInfo::class;
    }
}
