<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\DTOs;

class PackageDefinition
{
    /**
     * @param  array<string>  $skills
     */
    public function __construct(
        public readonly string $name,
        public readonly string $workspace,
        public readonly ?string $alias = null,
        public readonly ?string $url = null,
        public readonly array $skills = [],
    ) {}

    /**
     * Determine if the package has a custom alias.
     */
    public function isAliased(): bool
    {
        return $this->alias !== null && trim($this->alias) !== '';
    }

    /**
     * Determine if the package has a custom repository URL.
     */
    public function hasCustomUrl(): bool
    {
        return $this->url !== null && trim($this->url) !== '';
    }

    /**
     * Get effective directory name for the package in its workspace.
     */
    public function effectiveDirectory(): string
    {
        return $this->isAliased() ? (string) $this->alias : $this->name;
    }

    /**
     * Get canonical Composer package name ("vendor/package").
     * Resolves short names in fixed-vendor workspaces.
     */
    public function canonicalName(?string $workspaceVendor = null): string
    {
        if (str_contains($this->name, '/')) {
            return $this->name;
        }

        if ($workspaceVendor !== null && trim($workspaceVendor) !== '') {
            return strtolower(trim($workspaceVendor)).'/'.$this->name;
        }

        return $this->name;
    }

    /**
     * Convert to array representation for serialization or inspection.
     *
     * @return array{name: string, workspace: string, alias: ?string, url: ?string, skills: array<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'workspace' => $this->workspace,
            'alias' => $this->alias,
            'url' => $this->url,
            'skills' => $this->skills,
        ];
    }
}
