<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 * @template-covariant ValueType of string
 * @template-extends HeaderKey<ValueType>
 */
interface OptionalHeaderKey extends HeaderKey
{
    /**
     * @return ValueType
     */
    public function default(Headers $headers): string;
}
