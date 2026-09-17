<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use InvalidArgumentException;

class WorkspaceAlreadyExistsException extends InvalidArgumentException implements WorkspaceManifestException
{
    public function __construct(
        public readonly string $workspacePath,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Workspace [{$workspacePath}] is already registered in the manifest.");
    }
}
