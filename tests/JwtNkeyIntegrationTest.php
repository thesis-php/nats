<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[CoversClass(Client::class)]
#[CoversClass(JetStream::class)]
#[RequiresPhpExtension('sodium')]
final class JwtNkeyIntegrationTest extends NatsTestCase
{
    public function testJwtWithNkeyConnection(): void
    {
        $client = $this->clientWithJwtAuth();
        $js = $client->jetStream();

        $info = $js->accountInfo();

        self::assertSame(-1, $info->limits->maxMemory);
        self::assertSame(-1, $info->limits->maxStorage);
    }
}
