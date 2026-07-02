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

    public function __construct(Socket $socket, bool $upgradeTls = false)
    {
        $this->writer = new Writer($socket);

        /** @var Queue<Protocol\Frame> $queue */
        $queue = new Queue();
        $this->iterator = $queue->iterate();

        EventLoop::queue(static function () use ($socket, $queue, $upgradeTls): void {
            $parser = new Protocol\Parser($queue->push(...));

            try {
                // TLS upgrade (NATS `tls_required`, e.g. Synadia NGS). The server
                // sends a plaintext INFO first, then expects the client to run the
                // TLS handshake before CONNECT. We do BOTH here — in the single
                // fiber that owns socket reads — so setupTls() never races another
                // read (amphp forbids concurrent reads on a socket). We read up to
                // the end of the plaintext INFO line, hand those bytes to the
                // parser (so startup() still gets the INFO frame + its nonce), then
                // upgrade in place. NGS sends nothing between INFO and the upgrade,
                // so no post-INFO plaintext bytes are lost. After this the loop
                // below reads the encrypted stream exactly as normal.
                if ($upgradeTls) {
                    $preamble = '';
                    while (!str_contains($preamble, "\r\n")) {
                        $bytes = $socket->read();
                        if ($bytes === null) {
                            $parser->cancel();
                            $queue->complete();
                            $socket->close();
                            return;
                        }
                        $preamble .= $bytes;
                    }
                    // Upgrade BEFORE handing the INFO frame to the parser/queue.
                    // Order matters: parser->push() makes the INFO frame available
                    // to startup() (a DIFFERENT fiber), which then immediately
                    // writes CONNECT. If we pushed before setupTls() completed, that
                    // CONNECT would be written mid-TLS-handshake and corrupt the
                    // stream (server resets). setupTls() is synchronous-until-done
                    // here, so once it returns the channel is encrypted and it's
                    // safe to release the INFO frame.
                    $socket->setupTls();
                    $parser->push($preamble);
                }

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
