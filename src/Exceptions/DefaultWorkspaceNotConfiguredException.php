<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use RuntimeException;

class DefaultWorkspaceNotConfiguredException extends RuntimeException implements WorkspaceManifestException
{
    public function __construct(
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'No default workspace is configured in the manifest.');
    }
}
