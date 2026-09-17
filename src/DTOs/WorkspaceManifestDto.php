<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\DTOs;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;

/**
 * @implements ManifestDto<string, mixed>
 */
final class WorkspaceManifestDto implements ManifestDto
{
    public const DEFAULT_REPOSITORY_URL_TEMPLATE = 'git@github.com:{package}.git';

    /**
     * @var array<string, WorkspaceDefinition>
     */
    public const DEFAULT_EMPTY_WORKSPACES = [];

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_EMPTY_EXTRA = [];

    /**
     * @var array<int, string>
     */
    public const RESERVED_KEYS = ['$schema', 'default', 'repository_url_template', 'workspaces'];

    /**
     * @param  array<string, WorkspaceDefinition>  $workspaces
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly ?string $schema = null,
        public readonly ?string $default = null,
        public readonly string $repositoryUrlTemplate = self::DEFAULT_REPOSITORY_URL_TEMPLATE,
        public readonly array $workspaces = self::DEFAULT_EMPTY_WORKSPACES,
        public readonly array $extra = self::DEFAULT_EMPTY_EXTRA,
    ) {}

    /**
     * Create a DTO instance from raw manifest data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $schema = isset($data['$schema']) && is_string($data['$schema']) ? $data['$schema'] : null;
        $default = isset($data['default']) && is_string($data['default']) ? $data['default'] : null;
        $repoUrl = isset($data['repository_url_template']) && is_string($data['repository_url_template'])
            ? $data['repository_url_template']
            : self::DEFAULT_REPOSITORY_URL_TEMPLATE;

        $rawWorkspaces = (array) ($data['workspaces'] ?? self::DEFAULT_EMPTY_WORKSPACES);
        $workspaces = [];

        foreach ($rawWorkspaces as $wsName => $wsConfig) {
            if (! is_array($wsConfig)) {
                continue;
            }
            $workspaces[(string) $wsName] = WorkspaceDefinition::fromManifest((string) $wsName, $wsConfig);
        }

        $extra = array_diff_key($data, array_flip(self::RESERVED_KEYS));

        return new self(
            schema: $schema,
            default: $default,
            repositoryUrlTemplate: $repoUrl,
            workspaces: $workspaces,
            extra: $extra,
        );
    }

    /**
     * Convert the DTO to a normalized array for manifest storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $workspaces = [];
        foreach ($this->workspaces as $name => $definition) {
            $workspaces[$name] = $definition->toManifestArray();
        }
        ksort($workspaces);

        $data = [];
        if ($this->schema !== null) {
            $data['$schema'] = $this->schema;
        }
        $data['default'] = $this->default;
        $data['repository_url_template'] = $this->repositoryUrlTemplate;
        $data['workspaces'] = $workspaces;

        foreach ($this->extra as $key => $value) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Get a registered workspace definition by name.
     */
    public function getWorkspace(string $name): ?WorkspaceDefinition
    {
        return $this->workspaces[$name] ?? null;
    }

    /**
     * Determine if a workspace exists in the manifest.
     */
    public function hasWorkspace(string $name): bool
    {
        return isset($this->workspaces[$name]);
    }

    /**
     * Find a package definition by name or alias across specified or all workspaces.
     */
    public function findPackage(string $nameOrAlias, ?string $workspace = null): ?PackageDefinition
    {
        if ($workspace !== null) {
            return isset($this->workspaces[$workspace])
                ? $this->workspaces[$workspace]->findPackage($nameOrAlias)
                : null;
        }

        foreach ($this->workspaces as $wsDef) {
            $pkg = $wsDef->findPackage($nameOrAlias);
            if ($pkg !== null) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * Determine if a package is registered in specified or any workspace.
     */
    public function hasPackage(string $nameOrAlias, ?string $workspace = null): bool
    {
        return $this->findPackage($nameOrAlias, $workspace) !== null;
    }

    /**
     * Find workspace name containing the given package.
     */
    public function findPackageWorkspace(string $nameOrAlias): ?string
    {
        return $this->findPackage($nameOrAlias)?->workspace;
    }

    /**
     * Get list of package names across specified workspace, or all workspaces.
     *
     * @return array<int, string>
     */
    public function packageNames(?string $workspace = null): array
    {
        if ($workspace !== null) {
            return isset($this->workspaces[$workspace])
                ? $this->workspaces[$workspace]->packageNames()
                : [];
        }

        $names = [];
        foreach ($this->workspaces as $wsDef) {
            foreach ($wsDef->packageNames() as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Return a copy of the DTO with an added or updated workspace.
     */
    public function withWorkspace(
        WorkspaceDefinition|string $workspace,
        ?string $vendor = null,
        bool $asDefault = false
    ): static {
        $cleanVendor = $vendor !== null && trim($vendor) !== '' ? strtolower(trim($vendor)) : null;
        $workspaces = $this->workspaces;

        if ($workspace instanceof WorkspaceDefinition) {
            $name = $workspace->name;
            $definition = $cleanVendor !== null ? $workspace->withVendor($cleanVendor) : $workspace;
        } else {
            $name = $workspace;
            if (isset($workspaces[$name])) {
                $definition = $cleanVendor !== null ? $workspaces[$name]->withVendor($cleanVendor) : $workspaces[$name];
            } else {
                $definition = new WorkspaceDefinition(
                    name: $name,
                    vendor: $cleanVendor,
                    packages: [],
                );
            }
        }

        $workspaces[$name] = $definition;
        ksort($workspaces);

        $default = ($asDefault || $this->default === null) ? $name : $this->default;

        return new self(
            schema: $this->schema,
            default: $default,
            repositoryUrlTemplate: $this->repositoryUrlTemplate,
            workspaces: $workspaces,
            extra: $this->extra,
        );
    }

    /**
     * Return a copy of the DTO with an updated default workspace name.
     */
    public function withDefault(?string $default): static
    {
        return new self(
            schema: $this->schema,
            default: $default,
            repositoryUrlTemplate: $this->repositoryUrlTemplate,
            workspaces: $this->workspaces,
            extra: $this->extra,
        );
    }

    /**
     * Return a copy of the DTO with an updated repository URL template.
     */
    public function withRepositoryUrlTemplate(string $template): static
    {
        return new self(
            schema: $this->schema,
            default: $this->default,
            repositoryUrlTemplate: trim($template),
            workspaces: $this->workspaces,
            extra: $this->extra,
        );
    }

    /**
     * Return a copy of the DTO with a workspace removed.
     */
    public function withoutWorkspace(string $workspaceName, bool $reassignDefault = true): static
    {
        $workspaces = $this->workspaces;
        unset($workspaces[$workspaceName]);

        $default = ($reassignDefault && $this->default === $workspaceName)
            ? array_key_first($workspaces)
            : $this->default;

        return new self(
            schema: $this->schema,
            default: $default,
            repositoryUrlTemplate: $this->repositoryUrlTemplate,
            workspaces: $workspaces,
            extra: $this->extra,
        );
    }

    /**
     * Return a copy of the DTO with an updated workspace vendor prefix.
     */
    public function withWorkspaceVendor(string $name, ?string $vendor): static
    {
        $cleanVendor = $vendor !== null && trim($vendor) !== '' ? strtolower(trim($vendor)) : null;
        $workspaces = $this->workspaces;

        if (isset($workspaces[$name])) {
            $workspaces[$name] = $workspaces[$name]->withVendor($cleanVendor);
        } else {
            $workspaces[$name] = new WorkspaceDefinition(
                name: $name,
                vendor: $cleanVendor,
                packages: [],
            );
        }

        return new self(
            schema: $this->schema,
            default: $this->default,
            repositoryUrlTemplate: $this->repositoryUrlTemplate,
            workspaces: $workspaces,
            extra: $this->extra,
        );
    }

    /**
     * Return a copy of the DTO with an added or updated package in the given workspace.
     */
    public function withPackage(string $workspace, PackageDefinition $package): static
    {
        $dto = isset($this->workspaces[$workspace])
            ? $this
            : $this->withWorkspace($workspace);

        $workspaces = $dto->workspaces;
        $workspaces[$workspace] = $workspaces[$workspace]->withPackage($package);

        return new self(
            schema: $dto->schema,
            default: $dto->default ?? $workspace,
            repositoryUrlTemplate: $dto->repositoryUrlTemplate,
            workspaces: $workspaces,
            extra: $dto->extra,
        );
    }

    /**
     * Remove a package by name from a workspace (or auto-discover workspace if null).
     *
     * @return array{0: static, 1: bool} Tuple of [newManifestDto, wasRemoved]
     */
    public function withoutPackage(
        string $packageName,
        ?string $workspace = null,
        bool $pruneEmptyWorkspace = false
    ): array {
        $targetWorkspace = $workspace ?? $this->findPackageWorkspace($packageName);

        if ($targetWorkspace === null || ! isset($this->workspaces[$targetWorkspace])) {
            return [$this, false];
        }

        [$updatedWs, $removed] = $this->workspaces[$targetWorkspace]->withoutPackage($packageName);

        if (! $removed) {
            return [$this, false];
        }

        $workspaces = $this->workspaces;
        $workspaces[$targetWorkspace] = $updatedWs;
        $default = $this->default;

        if ($pruneEmptyWorkspace && empty($updatedWs->packages)) {
            unset($workspaces[$targetWorkspace]);
            if ($default === $targetWorkspace) {
                $default = array_key_first($workspaces);
            }
        }

        $newDto = new self(
            schema: $this->schema,
            default: $default,
            repositoryUrlTemplate: $this->repositoryUrlTemplate,
            workspaces: $workspaces,
            extra: $this->extra,
        );

        return [$newDto, true];
    }
}
