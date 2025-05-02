<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

#[CoversFunction('generateUniqueId')]
final class GenerateUniqueIdTest extends TestCase
{
    public function testGenerateUniqueId(): void
    {
        /** @var list<non-empty-string> $ids */
        $ids = [];

        for ($i = 0; $i < 100; ++$i) {
            $ids[] = $inboxId = generateUniqueId();
        }

        self::assertCount(\count($ids), array_unique($ids));
    }
}
