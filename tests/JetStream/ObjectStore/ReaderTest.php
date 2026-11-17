<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\FileIsNotAccessible;

#[CoversClass(StringReader::class)]
#[CoversClass(ResourceReader::class)]
#[CoversClass(StreamReader::class)]
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

    /**
     * @param list<string> $parts
     * @param positive-int $length
     * @param list<non-empty-string> $chunks
     */
    #[TestWith([['x', 'y', 'z'], 2, ['xy', 'z']])]
    #[TestWith([['xyz'], 1, ['x', 'y', 'z']])]
    #[TestWith([['ab', '', 'cdefg', 'h'], 3, ['abc', 'def', 'gh']])]
    #[TestWith([['abc', 'def'], 3, ['abc', 'def']])]
    #[TestWith([['abc'], 10, ['abc']])]
    #[TestWith([[], 10, []])]
    #[TestWith([[''], 10, []])]
    public function testStreamReader(array $parts, int $length, array $chunks): void
    {
        $rdr = new StreamReader(new ReadableIterableStream($parts));

        $result = [];

        while (!$rdr->eof()) {
            $result[] = $rdr->read($length);
        }

        self::assertSame($chunks, $result);
        self::assertNull($rdr->read($length));
    }

    public function testStreamReaderReadsWithoutEofCheck(): void
    {
        $rdr = new StreamReader(new ReadableBuffer('abcde'));

        self::assertSame('abc', $rdr->read(3));
        self::assertSame('de', $rdr->read(3));
        self::assertNull($rdr->read(3));
        self::assertTrue($rdr->eof());
    }

    public function testResourceReaderFailsOnReadError(): void
    {
        // A directory opens on Linux, and reading it fails.
        $handle = fopen(sys_get_temp_dir(), 'r');
        self::assertIsResource($handle);

        $rdr = new ResourceReader($handle);

        $this->expectException(FileIsNotAccessible::class);
        $this->expectExceptionMessageMatches('/^Failed to read the resource: .+Is a directory.$/');

        try {
            $rdr->read(10);
        } finally {
            fclose($handle);
        }
    }
}
