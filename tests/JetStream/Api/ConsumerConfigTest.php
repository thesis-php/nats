<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;

#[CoversClass(ConsumerConfig::class)]
final class ConsumerConfigTest extends TestCase
{
    public function testValid(): void
    {
        $this->expectNotToPerformAssertions();

        new ConsumerConfig();
        // Empty means no name: the consumer gets a generated one.
        new ConsumerConfig(name: '', durableName: '', filterSubject: '');
        new ConsumerConfig(
            name: 'processor',
            durableName: 'processor',
            deliverSubject: 'deliver.processor',
            deliverGroup: 'workers',
            filterSubject: 'orders.*',
            filterSubjects: ['orders.>'],
            priorityGroups: ['jobs', 'jobs/high'],
        );
    }

    /**
     * @param \Closure(): ConsumerConfig $create
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('provideInvalidCases')]
    public function testInvalid(\Closure $create, string $exception): void
    {
        $this->expectException($exception);
        $create();
    }

    /**
     * @return iterable<string, array{\Closure(): ConsumerConfig, class-string<\Throwable>}>
     */
    public static function provideInvalidCases(): iterable
    {
        yield 'name' => [static fn() => new ConsumerConfig(name: 'my.consumer'), InvalidName::class];
        yield 'durable name' => [static fn() => new ConsumerConfig(durableName: 'my consumer'), InvalidName::class];
        yield 'deliver subject with wildcard' => [static fn() => new ConsumerConfig(deliverSubject: 'deliver.*'), InvalidSubject::class];
        yield 'deliver group' => [static fn() => new ConsumerConfig(deliverSubject: 'deliver', deliverGroup: 'my workers'), InvalidSubject::class];
        yield 'filter subject' => [static fn() => new ConsumerConfig(filterSubject: 'orders.'), InvalidSubject::class];
        yield 'filter subjects' => [static fn() => new ConsumerConfig(filterSubjects: ['orders.*', '>.orders']), InvalidSubject::class];
        yield 'priority group' => [static fn() => new ConsumerConfig(priorityGroups: ['jobs', 'jobs.high']), InvalidName::class];
    }
}
