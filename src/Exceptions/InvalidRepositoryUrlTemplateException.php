<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use InvalidArgumentException;

class InvalidRepositoryUrlTemplateException extends InvalidArgumentException implements WorkspaceManifestException
{
    public function __construct(
        public readonly string $template,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Invalid repository URL template [{$template}]. Template must contain the '{package}' placeholder.");
    }
}
