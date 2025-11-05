<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use Amp\Pipeline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Iterator;
use function Amp\async;

#[CoversClass(PipelineIterator::class)]
final class PipelineIteratorTest extends TestCase
{
    public function testFilterMapComplete(): void
    {
        /** @var Pipeline\Queue<int> $queue */
        $queue = new Pipeline\Queue(bufferSize: 1);

        $complete = false;

        $future = async(static function () use (&$complete, $queue): void {
            $cursor = 0;

            /** @phpstan-ignore booleanNot.alwaysTrue */
            while (!$complete) {
                $queue->push(++$cursor);
            }
        });

        $iter = PipelineIterator::fromQueue($queue, static function () use (&$complete, $future): void {
            $complete = true;
            $future->await();
        });

        $iter = $iter->filterMap(static function (int $value): Iterator\Outcome {
            if ($value % 2 === 0) {
                return new Iterator\Emit($value);
            }

            if ($value > 100) {
                return Iterator\Complete::It;
            }

            return Iterator\Discard::It;
        });

        $elements = iterator_to_array($iter);

        $future->await();
        self::assertTrue($queue->isComplete());
        self::assertCount(50, $elements);
        self::assertCount(50, array_filter($elements, static fn(int $value): bool => $value % 2 === 0));
    }

    public function testFilterMapCancel(): void
    {
        /** @var Pipeline\Queue<int> $queue */
        $queue = new Pipeline\Queue(bufferSize: 1);

        $complete = false;

        $future = async(static function () use (&$complete, $queue): void {
            $cursor = 0;

            /** @phpstan-ignore booleanNot.alwaysTrue */
            while (!$complete) {
                $queue->push(++$cursor);
            }
        });

        $iter = PipelineIterator::fromQueue($queue, static function () use (&$complete, $future): void {
            $complete = true;
            $future->await();
        });

        $iter = $iter
            ->filter(static fn(int $value): bool => $value % 2 === 0)
            ->filterMap(static function (int $value): Iterator\Outcome {
                if ($value > 100) {
                    return new Iterator\Cancel(new \RuntimeException('Iterator cancelled.'));
                }

                return new Iterator\Emit($value);
            });

        $e = null;

        try {
            $elements = iterator_to_array($iter);
            $future->await();
            self::assertCount(50, $elements);
            self::assertCount(50, array_filter($elements, static fn(int $value): bool => $value % 2 === 0));
        } catch (\RuntimeException $e) {
        }

        self::assertEquals('Iterator cancelled.', $e?->getMessage());
        self::assertTrue($queue->isComplete());
    }
}
