<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Socket\Socket;
use Revolt\EventLoop;
use Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final readonly class Framer
{
    private Writer $writer;

    /** @var ConcurrentIterator<Protocol\Frame> */
    private ConcurrentIterator $iterator;

    public function __construct(Socket $socket)
    {
        $this->writer = new Writer($socket);

        /** @var Queue<Protocol\Frame> $queue */
        $queue = new Queue();
        $this->iterator = $queue->iterate();

        EventLoop::queue(static function () use ($socket, $queue): void {
            $parser = new Protocol\Parser($queue->push(...));

            try {
                while (($bytes = $socket->read()) !== null) {
                    $parser->push($bytes);
                }

                $parser->cancel();
                $queue->complete();
            } catch (\Throwable $e) {
                $queue->error($e);
            }

            $socket->close();
        });
    }

    public function readFrame(?Cancellation $cancellation = null): ?Protocol\Frame
    {
        return $this->iterator->continue($cancellation)
            ? $this->iterator->getValue()
            : null;
    }

    /**
     * @param Protocol\Frame|iterable<Protocol\Frame> $frames
     */
    public function writeFrame(Protocol\Frame|iterable $frames): void
    {
        if ($frames instanceof Protocol\Frame) {
            $frames = [$frames];
        }

        $this->writer->write([...$frames]);
        $this->writer->flush();
    }
}
