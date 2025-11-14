<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Revolt\EventLoop;
use Thesis\Nats\Client;
use Thesis\Nats\JetStream\Api\PullRequest;
use Thesis\Nats\JetStream\PullConsumeConfig;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Message;

/**
 * @internal
 */
final readonly class PullSupervisor
{
    private Sync\Barrier $barrier;

    /**
     * @param non-empty-string $subject
     * @param non-empty-string $replyTo
     */
    public function __construct(
        Client $nats,
        Encoder $json,
        PullConsumeConfig $config,
        string $subject,
        string $replyTo,
    ) {
        $barrier = new Sync\Barrier($config->batch);
        $barrier->dispatch();

        $this->barrier = $barrier;

        EventLoop::queue(static function () use (
            $barrier,
            $nats,
            $json,
            $config,
            $subject,
            $replyTo,
        ): void {
            foreach ($barrier as $_) {
                $nats->publish(
                    subject: $subject,
                    message: new Message($json->encode(new PullRequest(
                        expires: $config->expires,
                        batch: $config->batch,
                        maxBytes: $config->maxBytes,
                        noWait: $config->noWait,
                        heartbeat: $config->heartbeat,
                        minPending: $config->minPending,
                        minAckPending: $config->minAckPending,
                        pinId: $config->pinId,
                        group: $config->group,
                    ))),
                    replyTo: $replyTo,
                );
            }

            $barrier->close();
        });
    }

    public function next(): void
    {
        $this->barrier->dispatch();
    }

    public function request(): void
    {
        $this->barrier->arrive();
    }

    public function stop(): void
    {
        $this->barrier->close();
    }
}
