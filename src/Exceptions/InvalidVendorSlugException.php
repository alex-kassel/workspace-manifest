<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Exceptions;

use InvalidArgumentException;

class InvalidVendorSlugException extends InvalidArgumentException implements WorkspaceManifestException
{
    public function __construct(
        public readonly string $vendor,
        public readonly ?string $suggestion = null,
        ?string $message = null,
    ) {
        $msg = $message ?? "Invalid vendor slug [{$vendor}].";
        if ($this->suggestion !== null && ! str_contains($msg, $this->suggestion)) {
            $msg .= " Did you mean [{$this->suggestion}]?";
        }

        parent::__construct($msg);
    }
}
