<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Lib;

use Composer\InstalledVersions;

/** @internal */
const name = 'thesis/nats';

/** @internal */
const defaultVersion = 'dev';

/**
 * @internal
 * @return non-empty-string
 */
function version(): string
{
    /** @var ?non-empty-string $version */
    static $version = null;

    if ($version === null) {
        $version = defaultVersion;

        if (InstalledVersions::isInstalled(name) && ($installedVersion = InstalledVersions::getPrettyVersion(name)) !== null) {
            $version = $installedVersion ?: defaultVersion;
        }
    }

    return $version;
}
