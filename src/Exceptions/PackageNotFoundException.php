<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use InvalidArgumentException;

class PackageNotFoundException extends InvalidArgumentException implements WorkspaceManifestException
{
    public function __construct(
        public readonly string $packageName,
        public readonly ?string $workspacePath = null,
        ?string $message = null,
    ) {
        $msg = $workspacePath !== null
            ? "Package [{$packageName}] was not found in workspace [{$workspacePath}]."
            : "Package [{$packageName}] was not found in any workspace.";

        parent::__construct($message ?? $msg);
    }
}
