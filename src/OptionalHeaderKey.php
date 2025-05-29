<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 * @template ValueType
 * @template-extends HeaderKey<ValueType>
 */
interface OptionalHeaderKey extends HeaderKey
{
    /**
     * @return ValueType
     */
    public function default(Headers $headers): mixed;
}
