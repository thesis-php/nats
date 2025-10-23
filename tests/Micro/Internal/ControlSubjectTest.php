<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ControlSubject::class)]
final class ControlSubjectTest extends TestCase
{
    #[TestWith([
        new ControlSubject(Verb::Ping),
        '$SRV.PING',
    ])]
    #[TestWith([
        new ControlSubject(Verb::Ping, 'EchoService'),
        '$SRV.PING.EchoService',
    ])]
    #[TestWith([
        new ControlSubject(Verb::Ping, 'EchoService', '1'),
        '$SRV.PING.EchoService.1',
    ])]
    public function testControlSubject(ControlSubject $cs, string $value): void
    {
        self::assertSame($value, $cs->value);
    }
}
