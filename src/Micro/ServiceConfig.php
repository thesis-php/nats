<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Thesis\Nats\Internal\Validation\Validate;

/**
 * @api
 */
final readonly class ServiceConfig
{
    private const string SEMVER_REGEX = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';
    private const string NAME_REGEX = '/^[A-Za-z0-9\-_]+$/';

    /**
     * @param non-empty-string $name
     * @param non-empty-string $version
     * @param array<non-empty-string, string> $metadata
     * @param ?non-empty-string $queueGroup
     * @param ?\Closure(EndpointInfo): mixed $statsHandler returns the custom `data` of the endpoint stats, encoded as JSON
     * @param ?\Closure(Service): void $doneHandler called asynchronously once the service has stopped; exceptions go to $errorHandler, or to the Revolt error handler if none is configured
     * @param ?\Closure(Service, \Throwable): void $errorHandler called asynchronously for the first failed subscription and for exceptions from $doneHandler; exceptions from this callback go to the Revolt error handler
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description = '',
        public array $metadata = [],
        public ?string $queueGroup = null,
        public ?\Closure $statsHandler = null,
        public ?\Closure $doneHandler = null,
        public ?\Closure $errorHandler = null,
    ) {
        if (preg_match(self::NAME_REGEX, $this->name) !== 1) {
            throw new \InvalidArgumentException('Service name is not a valid string (only "A-Z, a-z, 0-9, _, -" are allowed).');
        }

        if (preg_match(self::SEMVER_REGEX, $this->version) !== 1) {
            throw new \InvalidArgumentException('Service version is not a valid semver string.');
        }

        if ($this->queueGroup !== null) {
            Validate::queueGroup($this->queueGroup);
        }
    }
}
