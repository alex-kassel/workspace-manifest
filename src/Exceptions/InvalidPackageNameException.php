<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use InvalidArgumentException;

class InvalidPackageNameException extends InvalidArgumentException implements WorkspaceManifestException
{
    public function __construct(
        public readonly string $packageName,
        public readonly ?string $suggestion = null,
        ?string $message = null,
    ) {
        $msg = $message ?? "Invalid package name [{$packageName}].";
        if ($this->suggestion !== null && ! str_contains($msg, $this->suggestion)) {
            $msg .= " Did you mean [{$this->suggestion}]?";
        }

        parent::__construct($msg);
    }
}
