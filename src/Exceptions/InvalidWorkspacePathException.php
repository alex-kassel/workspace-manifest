<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use InvalidArgumentException;

class InvalidWorkspacePathException extends InvalidArgumentException implements WorkspaceManifestException
{
    public function __construct(
        public readonly string $workspacePath,
        string $message,
    ) {
        parent::__construct("Invalid workspace path [{$workspacePath}]: {$message}");
    }
}
