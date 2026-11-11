<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket\Socket;
use Thesis\Nats\Internal\Protocol;

/**
 * Reads the socket only when a frame is asked for: between two frames nothing reads it,
 * so the socket can be upgraded to TLS right after the INFO frame.
 *
 * @internal
 */
final class Framer
{
    /** INFO is a single line: one this long means the peer is not a nats server. */
    private const int MAX_STARTUP_LINE = 1024 * 1024;

    private readonly Writer $writer;

    private readonly Protocol\Parser $parser;

    /** @var \SplQueue<Protocol\Frame> the frames parsed but not read yet */
    private readonly \SplQueue $frames;

    private bool $eof = false;

    /** The bytes read after the startup frame, not parsed yet. */
    private string $unparsed = '';

    public function __construct(
        private readonly Socket $socket,
    ) {
        $this->writer = new Writer($socket);

        /** @var \SplQueue<Protocol\Frame> $frames */
        $frames = new \SplQueue();
        $this->frames = $frames;
        $this->parser = new Protocol\Parser($frames->push(...));
    }

    /**
     * Reads the first line the server sends (INFO), and nothing past it: whatever came along stays unparsed,
     * so that the plain bytes are never taken for the ones that come after the upgrade to TLS, see {@see hasUnread()}.
     *
     * @return ?Protocol\Frame null once the peer has closed the connection
     * @throws \Throwable
     */
    public function readStartupFrame(?Cancellation $cancellation = null): ?Protocol\Frame
    {
        $buffer = $this->unparsed;
        $this->unparsed = '';

        try {
            while (!str_contains($buffer, "\r\n")) {
                if (\strlen($buffer) > self::MAX_STARTUP_LINE) {
                    throw new \UnexpectedValueException('The startup frame of the nats server is too long.');
                }

                $bytes = $this->socket->read($cancellation);

                if ($bytes === null) {
                    $this->eof = true;
                    $this->socket->close();

                    return null;
                }

                $buffer .= $bytes;
            }
        } catch (CancelledException $e) {
            $this->unparsed = $buffer;

            throw $e;
        } catch (\Throwable $e) {
            $this->eof = true;
            $this->socket->close();

            throw $e;
        }

        $end = (int) strpos($buffer, "\r\n");
        $this->unparsed = substr($buffer, $end + 2);
        $this->parse(substr($buffer, 0, $end + 2));

        return $this->frames->isEmpty()
            ? throw new \UnexpectedValueException('The startup line of the nats server is not a frame of its own.')
            : $this->frames->shift();
    }

    /**
     * Whether anything has been received but not read yet.
     */
    public function hasUnread(): bool
    {
        return $this->unparsed !== '' || !$this->frames->isEmpty();
    }

    /**
     * @return ?Protocol\Frame null once the peer has closed the connection
     * @throws \Throwable
     */
    public function readFrame(?Cancellation $cancellation = null): ?Protocol\Frame
    {
        while ($this->frames->isEmpty()) {
            if ($this->unparsed !== '') {
                [$bytes, $this->unparsed] = [$this->unparsed, ''];
                $this->parse($bytes);

                continue;
            }

            if ($this->eof) {
                return null;
            }

            try {
                $bytes = $this->socket->read($cancellation);

                if ($bytes === null) {
                    $this->eof = true;
                    $this->parser->cancel();
                    $this->socket->close();

                    continue;
                }
            } catch (CancelledException $e) {
                // Nothing has been read: the next call reads on.
                throw $e;
            } catch (\Throwable $e) {
                $this->eof = true;
                $this->socket->close();

                throw $e;
            }

            $this->parse($bytes);
        }

        return $this->frames->shift();
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

    /**
     * @throws \Throwable
     */
    private function parse(string $bytes): void
    {
        try {
            $this->parser->push($bytes);
        } catch (\Throwable $e) {
            $this->eof = true;
            $this->socket->close();

            throw $e;
        }
    }
}
