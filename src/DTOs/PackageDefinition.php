<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\DTOs;

final class PackageDefinition
{
    /**
     * @var array<string>
     */
    public const DEFAULT_EMPTY_SKILLS = [];

    public const DEFAULT_EMPTY_STRING = '';

    /**
     * @param  array<string>  $skills
     */
    public function __construct(
        public readonly string $name,
        public readonly string $workspace,
        public readonly ?string $alias = null,
        public readonly ?string $url = null,
        public readonly array $skills = self::DEFAULT_EMPTY_SKILLS,
    ) {}

    /**
     * Create a PackageDefinition from a raw manifest entry (string or array).
     *
     * @param  string|array<string, mixed>  $entry
     */
    public static function fromManifest(string $workspace, string|array $entry): self
    {
        if (is_string($entry)) {
            return new self(
                name: $entry,
                workspace: $workspace,
            );
        }

        $name = isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : self::DEFAULT_EMPTY_STRING;
        $alias = isset($entry['alias']) && is_string($entry['alias']) ? $entry['alias'] : null;
        $url = isset($entry['url']) && is_string($entry['url']) ? $entry['url'] : null;
        $skills = isset($entry['skills']) && is_array($entry['skills'])
            ? array_values(array_filter($entry['skills'], 'is_string'))
            : self::DEFAULT_EMPTY_SKILLS;

        return new self(
            name: $name,
            workspace: $workspace,
            alias: $alias,
            url: $url,
            skills: $skills,
        );
    }

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

    /**
     * Convert to raw manifest entry format (string if simple, array if has metadata).
     *
     * @return string|array<string, mixed>
     */
    public function toManifestEntry(): string|array
    {
        if (! $this->isAliased() && ! $this->hasCustomUrl() && empty($this->skills)) {
            return $this->name;
        }

        $entry = ['name' => $this->name];

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
