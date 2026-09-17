<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use InvalidArgumentException;

class WorkspaceNotFoundException extends InvalidArgumentException implements WorkspaceManifestException
{
    public function __construct(
        public readonly string $workspacePath,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Workspace [{$workspacePath}] is not registered in the manifest.");
    }
}
