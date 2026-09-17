<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Validation;

final class PackageValidationResult extends ValidationResult
{
    public function __construct(
        bool $isValid,
        ?string $errorMessage = null,
        ?string $suggestion = null,
        ?string $normalized = null,
        public readonly ?string $vendor = null,
        public readonly ?string $package = null,
        public readonly ?string $canonicalName = null,
        public readonly ?string $shortName = null,
        public readonly ?string $storedName = null,
    ) {
        parent::__construct(
            isValid: $isValid,
            errorMessage: $errorMessage,
            suggestion: $suggestion,
            normalized: $normalized,
        );
    }

    public static function packageValid(
        string $vendor,
        string $package,
        string $canonicalName,
        string $shortName,
        string $storedName,
    ): self {
        return new self(
            isValid: true,
            normalized: $canonicalName,
            vendor: $vendor,
            package: $package,
            canonicalName: $canonicalName,
            shortName: $shortName,
            storedName: $storedName,
        );
    }

    public static function packageInvalid(
        string $errorMessage,
        ?string $suggestion = null,
        ?string $vendor = null,
        ?string $package = null,
        ?string $canonicalName = null,
    ): self {
        return new self(
            isValid: false,
            errorMessage: $errorMessage,
            suggestion: $suggestion,
            vendor: $vendor,
            package: $package,
            canonicalName: $canonicalName,
        );
    }
}
