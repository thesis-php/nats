<?php

declare(strict_types=1);

namespace JetStream\ObjectStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\JetStream\ObjectStore\ResourceReader;
use Thesis\Nats\JetStream\ObjectStore\StringReader;

#[CoversClass(StringReader::class)]
#[CoversClass(ResourceReader::class)]
final class ReaderTest extends TestCase
{
    /**
     * @param non-empty-string $data
     * @param positive-int $length
     * @param list<non-empty-string> $chunks
     */
    #[TestWith([
        'xyz',
        1,
        ['x', 'y', 'z'],
    ])]
    #[TestWith([
        'xyz',
        2,
        ['xy', 'z'],
    ])]
    #[TestWith([
        'xyz',
        3,
        ['xyz'],
    ])]
    #[TestWith([
        'xyz',
        10,
        ['xyz'],
    ])]
    public function testStringReader(string $data, int $length, array $chunks): void
    {
        $rdr = new StringReader($data);

        $result = [];

        while (!$rdr->eof()) {
            $result[] = $rdr->read($length);
        }

        self::assertSame($chunks, $result);
    }

    /**
     * @param non-empty-string $data
     * @param positive-int $length
     * @param list<non-empty-string> $chunks
     */
    #[TestWith([
        'xyz',
        1,
        ['x', 'y', 'z'],
    ])]
    #[TestWith([
        'xyz',
        2,
        ['xy', 'z'],
    ])]
    #[TestWith([
        'xyz',
        3,
        ['xyz'],
    ])]
    #[TestWith([
        'xyz',
        10,
        ['xyz'],
    ])]
    public function testResourceReader(string $data, int $length, array $chunks): void
    {
        $handle = fopen('php://memory', 'a+');
        self::assertIsResource($handle);

        fwrite($handle, $data);
        fseek($handle, 0);

        $rdr = new ResourceReader($handle);

        $result = [];

        while (!$rdr->eof()) {
            $chunk = $rdr->read($length);
            if ($chunk !== null) {
                $result[] = $chunk;
            }
        }

        self::assertSame($chunks, $result);
    }
}
