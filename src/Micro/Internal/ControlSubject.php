<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

/**
 * @internal
 */
final readonly class ControlSubject
{
    /** @var non-empty-string */
    public string $value;

    public function __construct(
        Verb $verb,
        string $name = '',
        string $id = '',
    ) {
        $value = "\$SRV.{$verb->value}";

        if ($name !== '') {
            $value .= ".{$name}";
        }

        if ($id !== '') {
            $value .= ".{$id}";
        }

        $this->value = $value;
    }
}
