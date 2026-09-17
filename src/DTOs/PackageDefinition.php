<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * @implements Arrayable<string, mixed>
 */
final class PackageDefinition implements Arrayable
{
    /**
     * @param  array<string>  $skills
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $name,
        public readonly string $workspace,
        public readonly ?string $alias = null,
        public readonly ?string $url = null,
        public readonly array $skills = [],
    ) {
        if (! str_contains($this->name, '/')) {
            throw new InvalidArgumentException(
                "PackageDefinition requires a canonical Composer package name including vendor (e.g. 'vendor/package'), given [{$this->name}]."
            );
        }
    }

    /**
     * Create a PackageDefinition from a raw manifest entry (string or array).
     * Automatically prepends workspace vendor if input is a short name.
     *
     * @param  string|array<string, mixed>  $entry
     */
    public static function fromManifest(string $workspace, string|array $entry, ?string $workspaceVendor = null): self
    {
        $cleanVendor = $workspaceVendor !== null && trim($workspaceVendor) !== ''
            ? strtolower(trim($workspaceVendor))
            : null;

        if (is_string($entry)) {
            $rawName = strtolower(trim($entry));
            $canonicalName = (! str_contains($rawName, '/') && $cleanVendor !== null)
                ? "{$cleanVendor}/{$rawName}"
                : $rawName;

            return new self(
                name: $canonicalName,
                workspace: $workspace,
            );
        }

        $rawName = isset($entry['name']) && is_string($entry['name']) ? strtolower(trim($entry['name'])) : '';
        $canonicalName = (! str_contains($rawName, '/') && $cleanVendor !== null)
            ? "{$cleanVendor}/{$rawName}"
            : $rawName;

        $alias = isset($entry['alias']) && is_string($entry['alias']) ? trim($entry['alias']) : null;
        $url = isset($entry['url']) && is_string($entry['url']) ? trim($entry['url']) : null;
        $skills = isset($entry['skills']) && is_array($entry['skills'])
            ? array_values(array_filter($entry['skills'], 'is_string'))
            : [];

        return new self(
            name: $canonicalName,
            workspace: $workspace,
            alias: $alias,
            url: $url,
            skills: $skills,
        );
    }

    /**
     * Determine if the package has a custom alias.
     *
     * @phpstan-assert-if-true !null $this->alias
     */
    public function isAliased(): bool
    {
        return $this->alias !== null && trim($this->alias) !== '';
    }

    /**
     * Determine if the package has a custom repository URL.
     *
     * @phpstan-assert-if-true !null $this->url
     */
    public function hasCustomUrl(): bool
    {
        return $this->url !== null && trim($this->url) !== '';
    }

    /**
     * Get the vendor prefix of the package.
     */
    public function vendor(): string
    {
        return explode('/', $this->name, 2)[0];
    }

    /**
     * Get the short package slug without vendor prefix.
     */
    public function shortName(): string
    {
        return explode('/', $this->name, 2)[1];
    }

    /**
     * Get effective directory name for the package in its workspace.
     */
    public function effectiveDirectory(?string $workspaceVendor = null): string
    {
        if ($this->isAliased()) {
            return (string) $this->alias;
        }

        if ($workspaceVendor !== null && str_starts_with($this->name, strtolower(trim($workspaceVendor)).'/')) {
            return $this->shortName();
        }

        return $this->name;
    }

    /**
     * Get canonical Composer package name ("vendor/package").
     * Since $name is always canonical, this returns $this->name.
     */
    public function canonicalName(?string $workspaceVendor = null): string
    {
        return $this->name;
    }

    /**
     * Convert to normalized array representation (Arrayable contract).
     *
     * @return array{name: string, alias?: string, url?: string, skills?: array<string>}
     */
    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if ($this->isAliased()) {
            $data['alias'] = $this->alias;
        }

        if ($this->hasCustomUrl()) {
            $data['url'] = $this->url;
        }

        if (! empty($this->skills)) {
            $data['skills'] = array_values(array_unique($this->skills));
        }

        return $data;
    }

    /**
     * Convert to raw manifest entry format for storage in workspace.json.
     * Automatically collapses vendor prefix if matching $workspaceVendor.
     *
     * @return string|array<string, mixed>
     */
    public function toManifestEntry(?string $workspaceVendor = null): string|array
    {
        $cleanVendor = $workspaceVendor !== null && trim($workspaceVendor) !== ''
            ? strtolower(trim($workspaceVendor))
            : null;

        $storedName = ($cleanVendor !== null && str_starts_with($this->name, "{$cleanVendor}/"))
            ? substr($this->name, strlen("{$cleanVendor}/"))
            : $this->name;

        if (! $this->isAliased() && ! $this->hasCustomUrl() && empty($this->skills)) {
            return $storedName;
        }

        $entry = ['name' => $storedName];

        if ($this->isAliased()) {
            $entry['alias'] = $this->alias;
        }

        if ($this->hasCustomUrl()) {
            $entry['url'] = $this->url;
        }

        if (! empty($this->skills)) {
            $entry['skills'] = array_values(array_unique($this->skills));
        }

        return $entry;
    }
}
