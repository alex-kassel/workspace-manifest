<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Validation;

use AlexKassel\WorkspaceManifest\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;
use Illuminate\Support\Str;

final class WorkspaceValidator
{
    public const SEGMENT_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*$/';

    /**
     * Validate Composer package name syntax and resolve parts.
     */
    public static function validatePackageName(string $input, ?string $workspaceVendor = null): PackageValidationResult
    {
        $normalized = str_replace('\\', '/', trim($input));

        if ($normalized === '') {
            return PackageValidationResult::packageInvalid('Package name cannot be empty.');
        }

        if (str_contains($normalized, '/')) {
            $parts = explode('/', $normalized);
            if (count($parts) > 2) {
                return PackageValidationResult::packageInvalid(
                    "Invalid package name [{$input}]. Composer package name can only contain at most one slash ('/') separating vendor and package name."
                );
            }

            [$rawVendor, $rawPackage] = $parts;
            $rawVendor = trim($rawVendor);
            $rawPackage = trim($rawPackage);
            $vendor = strtolower($rawVendor);
            $package = strtolower($rawPackage);

            $validVendor = (bool) preg_match(self::SEGMENT_PATTERN, $rawVendor);
            $validPackage = (bool) preg_match(self::SEGMENT_PATTERN, $rawPackage);

            if (! $validVendor || ! $validPackage) {
                $suggestedVendor = Str::slug($vendor);
                $suggestedPackage = Str::slug($package);

                return PackageValidationResult::packageInvalid(
                    "Invalid package name [{$input}]. Composer vendor and package names must contain only lowercase letters, numbers, dashes, underscores, and dots.",
                    "{$suggestedVendor}/{$suggestedPackage}",
                    $vendor,
                    $package,
                    "{$vendor}/{$package}",
                );
            }

            $canonicalName = "{$vendor}/{$package}";
            $shortName = $package;
            $storedName = ($workspaceVendor !== null && $vendor === strtolower(trim($workspaceVendor)))
                ? $shortName
                : $canonicalName;

            return PackageValidationResult::packageValid($vendor, $package, $canonicalName, $shortName, $storedName);
        }

        // Single segment input
        $rawPackage = $normalized;
        $package = strtolower($rawPackage);
        $validPackage = (bool) preg_match(self::SEGMENT_PATTERN, $rawPackage);

        if ($workspaceVendor !== null && trim($workspaceVendor) !== '') {
            $rawWsVendor = trim($workspaceVendor);
            $vendor = strtolower($rawWsVendor);
            $validWsVendor = (bool) preg_match(self::SEGMENT_PATTERN, $rawWsVendor);

            if (! $validPackage || ! $validWsVendor) {
                $suggested = Str::slug($package);

                return PackageValidationResult::packageInvalid(
                    "Invalid package name [{$input}]. Composer package names must contain only lowercase letters, numbers, dashes, underscores, and dots.",
                    $suggested,
                    $vendor,
                    $package,
                    "{$vendor}/{$package}",
                );
            }

            return PackageValidationResult::packageValid($vendor, $package, "{$vendor}/{$package}", $package, $package);
        }

        if (! $validPackage) {
            $suggested = Str::slug($package);

            return PackageValidationResult::packageInvalid(
                "Invalid package name [{$input}]. Composer package names must contain only lowercase letters, numbers, dashes, underscores, and dots.",
                "my-vendor/{$suggested}",
                '',
                $package,
                $package,
            );
        }

        return PackageValidationResult::packageInvalid(
            "Package name [{$input}] requires a vendor prefix in 'vendor/package' format when workspace has no default vendor.",
            "my-vendor/{$package}",
            '',
            $package,
            $package,
        );
    }

    /**
     * Validate workspace vendor slug.
     */
    public static function validateVendorSlug(?string $input, bool $nullable = true): ValidationResult
    {
        if ($input === null) {
            if ($nullable) {
                return ValidationResult::valid(null);
            }

            return ValidationResult::invalid('Vendor slug cannot be empty.');
        }

        $trimmed = trim($input);
        if ($trimmed === '') {
            if ($nullable) {
                return ValidationResult::valid(null);
            }

            return ValidationResult::invalid('Vendor slug cannot be empty.');
        }

        if (preg_match(self::SEGMENT_PATTERN, $trimmed)) {
            return ValidationResult::valid(strtolower($trimmed));
        }

        $suggestion = Str::slug($trimmed);
        if ($suggestion === '') {
            $suggestion = null;
        }

        return ValidationResult::invalid(
            "Invalid vendor slug [{$input}]. Vendor slug must contain only lowercase letters, numbers, dashes, underscores, and dots.",
            $suggestion,
        );
    }

    /**
     * Validate and normalize workspace path.
     */
    public static function validateWorkspacePath(string $input): ValidationResult
    {
        try {
            $normalized = WorkspaceManifest::normalizeWorkspacePath($input);

            return ValidationResult::valid($normalized);
        } catch (InvalidWorkspacePathException $e) {
            return ValidationResult::invalid($e->getMessage());
        }
    }

    /**
     * Validate package directory alias.
     */
    public static function validatePackageAlias(?string $input): ValidationResult
    {
        if ($input === null) {
            return ValidationResult::valid(null);
        }

        $trimmed = trim($input);
        if ($trimmed === '') {
            return ValidationResult::valid(null);
        }

        if (str_contains($trimmed, '/') || str_contains($trimmed, '\\') || $trimmed === '.' || $trimmed === '..') {
            return ValidationResult::invalid("Invalid package alias [{$input}]. Aliases cannot contain slashes or directory traversal characters.");
        }

        return ValidationResult::valid($trimmed);
    }

    /**
     * Validate Git repository URL template.
     */
    public static function validateRepositoryUrlTemplate(string $input): ValidationResult
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return ValidationResult::invalid('Repository URL template cannot be empty.');
        }

        if (! str_contains($trimmed, '{package}')) {
            return ValidationResult::invalid("Repository URL template [{$input}] must contain the '{package}' placeholder.");
        }

        return ValidationResult::valid($trimmed);
    }

    /**
     * Validate agent skills list.
     */
    public static function validateSkills(mixed $input): ValidationResult
    {
        if (! is_array($input)) {
            return ValidationResult::invalid('Skills must be an array of non-empty strings.');
        }

        $validated = [];
        foreach ($input as $index => $item) {
            if (! is_string($item) || trim($item) === '') {
                return ValidationResult::invalid("Skill at index [{$index}] must be a non-empty string.");
            }
            $validated[] = trim($item);
        }

        return ValidationResult::valid(array_values(array_unique($validated)));
    }
}
