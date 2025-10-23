<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Delivery;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Micro\EndpointInfo;
use Thesis\Nats\Micro\EndpointStats;
use Thesis\Nats\Micro\Request;
use Amp;

/**
 * @internal
 */
final class EndpointHandler
{
    /** @var non-negative-int */
    private int $requests = 0;

    /** @var non-negative-int */
    private int $errors = 0;
    private float $processingTime = 0;
    private ?\Throwable $lastError = null;

    /**
     * @param callable(Request): void $handler
     */
    public function __construct(
        public readonly EndpointInfo $info,
        private readonly mixed $handler,
        private readonly Encoder $encoder,
    ) {}

    public function handle(Delivery $delivery): void
    {
        $start = Amp\now();

        try {
            ($this->handler)(new Request($delivery, $this->encoder));
            ++$this->requests;
            $this->processingTime += Amp\now() - $start;
        } catch (\Throwable $e) {
            ++$this->errors;
            $this->lastError = $e;
        }
    }

    public function reset(): void
    {
        $this->requests = 0;
        $this->errors = 0;
        $this->processingTime = 0;
        $this->lastError = null;
    }

    public function stats(): EndpointStats
    {
        return new EndpointStats(
            name: $this->info->name,
            subject: $this->info->subject,
            queueGroup: $this->info->queueGroup,
            numRequests: $this->requests,
            numErrors: $this->errors,
            lastError: $this->lastError,
            processingTime: $this->processingTime,
            averageProcessingTime: $this->requests > 0 ? $this->processingTime / $this->requests : 0,
        );
    }
}
