<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Delivery;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Micro\EndpointInfo;
use Thesis\Nats\Micro\EndpointStats;
use Thesis\Nats\Micro\Request;
use Thesis\Nats\Micro\ServiceError;

/**
 * @internal
 */
final class EndpointHandler
{
    private const int INTERNAL_ERROR_CODE = 500;

    /** @var non-negative-int */
    private int $requests = 0;

    /** @var non-negative-int */
    private int $errors = 0;

    /** @var non-negative-int nanoseconds */
    private int $processingTime = 0;

    private ?\Throwable $lastError = null;

    /**
     * @param callable(Request): void $handler
     * @param ?\Closure(EndpointInfo): mixed $statsHandler
     */
    public function __construct(
        public readonly EndpointInfo $info,
        private readonly mixed $handler,
        private readonly Encoder $encoder,
        private readonly ?\Closure $statsHandler = null,
    ) {}

    public function handle(Delivery $delivery): void
    {
        $start = (int) hrtime(true);
        $outcome = new RequestOutcome();
        $request = new Request($delivery, $this->encoder, $outcome);

        try {
            ($this->handler)($request);
        } catch (\Throwable $e) {
            // A failure after the handler has replied is counted as is: the requester has got its response.
            $outcome->fail(!$outcome->replied && $request->reply !== null ? self::replyWithError($request, $e) : $e);
        }

        ++$this->requests;
        $this->processingTime += max(0, (int) hrtime(true) - $start);

        if ($outcome->error !== null) {
            $this->fail($outcome->error);
        }
    }

    /**
     * Counts an error that is not tied to a request, e.g. the endpoint subscription failure.
     */
    public function fail(\Throwable $error): void
    {
        ++$this->errors;
        $this->lastError = $error;
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
            averageProcessingTime: $this->requests > 0 ? max(0, intdiv($this->processingTime, $this->requests)) : 0,
            data: $this->statsHandler !== null ? ($this->statsHandler)($this->info) : null,
        );
    }

    /**
     * A handler that throws still answers the requester instead of leaving it waiting for the timeout:
     * a {@see ServiceError} with its own code, anything else with 500 and the exception message.
     *
     * @return ServiceError the error replied with, counted in the stats so that they match the reply
     */
    private static function replyWithError(Request $request, \Throwable $e): ServiceError
    {
        if ($e instanceof ServiceError) {
            $error = $e;
        } else {
            $message = $e->getMessage();
            $error = new ServiceError(self::INTERNAL_ERROR_CODE, $message !== '' ? $message : $e::class, previous: $e);
        }

        try {
            $request->error($error->getCode(), $error->description, $error->data);
        } catch (\Throwable) {
            // The reply cannot be sent, e.g. the connection is closed: the handler's error is the one to report.
        }

        return $error;
    }
}
