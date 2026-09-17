<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use InvalidArgumentException;

class InvalidPackageAliasException extends InvalidArgumentException implements WorkspaceManifestException
{
    public function __construct(
        public readonly string $alias,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Invalid package alias [{$alias}]. Aliases cannot contain slashes or directory traversal characters.");
    }
}
