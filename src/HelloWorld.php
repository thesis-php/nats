<?php

declare(strict_types=1);

namespace Thesis\Template;

/**
 * @api
 */
final readonly class HelloWorld
{
    public static function message(): string
    {
        return 'Hello world!';
    }
}
