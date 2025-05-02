<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

#[CoversFunction('generateInboxId')]
final class GenerateInboxIdTest extends TestCase
{
    public function testGenerateInboxId(): void
    {
        /** @var list<non-empty-string> $ids */
        $ids = [];

        for ($i = 0; $i < 100; ++$i) {
            $ids[] = $inboxId = generateInboxId();
            self::assertStringStartsWith('_INBOX.', $inboxId);
        }

        self::assertCount(\count($ids), array_unique($ids));
    }
}
