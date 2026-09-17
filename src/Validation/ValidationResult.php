<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Validation;

class ValidationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly ?string $errorMessage = null,
        public readonly ?string $suggestion = null,
        public readonly mixed $normalized = null,
    ) {}

    public static function valid(mixed $normalized = null): self
    {
        return new self(
            isValid: true,
            normalized: $normalized,
        );
    }

    public static function invalid(string $errorMessage, ?string $suggestion = null): self
    {
        return new self(
            isValid: false,
            errorMessage: $errorMessage,
            suggestion: $suggestion,
        );
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function isInvalid(): bool
    {
        return ! $this->isValid;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function suggestion(): ?string
    {
        return $this->suggestion;
    }

    public function normalized(): mixed
    {
        return $this->normalized;
    }
}
