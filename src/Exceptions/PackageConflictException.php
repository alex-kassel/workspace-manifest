<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use RuntimeException;

class PackageConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $conflictingName,
        public readonly string $existingName,
        string $message,
    ) {
        parent::__construct($message);
    }
}
