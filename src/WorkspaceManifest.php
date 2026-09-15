<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest;

use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema;

class WorkspaceManifest
{
    public const DEFAULT_FILENAME = 'workspace.json';

    public const DEFAULT_REPOSITORY_URL_TEMPLATE = 'git@github.com:{package}.git';

    protected Manifest $manifest;

    public function __construct(
        Manifest|string $manifest = self::DEFAULT_FILENAME,
    ) {
        if (is_string($manifest)) {
            $this->manifest = Manifest::open($manifest, new WorkspaceSchema);
        } else {
            $this->manifest = $manifest;
        }
    }

    /**
     * Open a WorkspaceManifest instance for given path.
     */
    public static function open(string $path = self::DEFAULT_FILENAME): self
    {
        return new self($path);
    }

    /**
     * Get underlying ManifestEngine instance.
     */
    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    /**
     * Check if manifest file exists.
     */
    public function exists(): bool
    {
        return $this->manifest->exists();
    }

    /**
     * Initialize manifest file with default schema state if not exists.
     */
    public function init(): self
    {
        $this->manifest->init();

        return $this;
    }

    /**
     * Get default workspace name.
     */
    public function getDefaultWorkspace(): ?string
    {
        return $this->manifest->get('default');
    }

    /**
     * Set default workspace name.
     */
    public function setDefaultWorkspace(?string $workspace): self
    {
        $this->manifest->set('default', $workspace);

        return $this;
    }

    /**
     * Get repository URL template.
     */
    public function getRepositoryUrlTemplate(): string
    {
        return (string) $this->manifest->get('repository_url_template', self::DEFAULT_REPOSITORY_URL_TEMPLATE);
    }

    /**
     * Set repository URL template.
     */
    public function setRepositoryUrlTemplate(string $template): self
    {
        $this->manifest->set('repository_url_template', trim($template));

        return $this;
    }

    /**
     * Get all workspaces configuration.
     *
     * @return array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<string>}>}>
     */
    public function getWorkspaces(): array
    {
        /** @var array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<string>}>}> */
        return (array) $this->manifest->get('workspaces', []);
    }

    /**
     * Get all registered workspace names/paths.
     *
     * @return array<int, string>
     */
    public function getWorkspaceNames(): array
    {
        return array_keys($this->getWorkspaces());
    }

    /**
     * Determine if a workspace is registered.
     */
    public function hasWorkspace(string $workspace): bool
    {
        return $this->manifest->has("workspaces.{$workspace}");
    }

    /**
     * Register a new workspace.
     */
    public function registerWorkspace(string $workspace, ?string $vendor = null, bool $asDefault = false): self
    {
        $this->manifest->mutate(function (array $data) use ($workspace, $vendor, $asDefault): array {
            if (! isset($data['workspaces']) || ! is_array($data['workspaces'])) {
                $data['workspaces'] = [];
            }

            if (! isset($data['workspaces'][$workspace])) {
                $data['workspaces'][$workspace] = [
                    'vendor' => $vendor !== null ? strtolower(trim($vendor)) : null,
                    'packages' => [],
                ];
            } elseif ($vendor !== null) {
                $data['workspaces'][$workspace]['vendor'] = strtolower(trim($vendor));
            }

            if ($asDefault || ($data['default'] ?? null) === null) {
                $data['default'] = $workspace;
            }

            return $data;
        });

        return $this;
    }

    /**
     * Unregister a workspace.
     */
    public function unregisterWorkspace(string $workspace, bool $reassignDefault = true): self
    {
        $this->manifest->mutate(function (array $data) use ($workspace, $reassignDefault): array {
            if (isset($data['workspaces'][$workspace])) {
                unset($data['workspaces'][$workspace]);
            }

            if ($reassignDefault && ($data['default'] ?? null) === $workspace) {
                $data['default'] = array_key_first($data['workspaces'] ?? []) ?? null;
            }

            return $data;
        });

        return $this;
    }

    /**
     * Get vendor prefix configured for a workspace.
     */
    public function getWorkspaceVendor(string $workspace): ?string
    {
        return $this->manifest->get("workspaces.{$workspace}.vendor");
    }

    /**
     * Set or clear vendor prefix configured for a workspace.
     */
    public function setWorkspaceVendor(string $workspace, ?string $vendor): self
    {
        $this->manifest->mutate(function (array $data) use ($workspace, $vendor): array {
            if (! isset($data['workspaces'][$workspace])) {
                $data['workspaces'][$workspace] = [
                    'vendor' => $vendor !== null ? strtolower(trim($vendor)) : null,
                    'packages' => [],
                ];
            } else {
                $data['workspaces'][$workspace]['vendor'] = $vendor !== null ? strtolower(trim($vendor)) : null;
            }

            return $data;
        });

        return $this;
    }

    /**
     * Get all raw package entries in given workspace, or all workspaces.
     *
     * @return array<string|int, mixed>
     */
    public function getRawPackages(?string $workspace = null): array
    {
        if ($workspace !== null) {
            return (array) $this->manifest->get("workspaces.{$workspace}.packages", []);
        }

        $all = [];
        foreach ($this->getWorkspaces() as $wsKey => $wsConfig) {
            $all[$wsKey] = (array) ($wsConfig['packages'] ?? []);
        }

        return $all;
    }

    /**
     * Get list of package names across specified workspace, or all workspaces.
     *
     * @return array<int, string>
     */
    public function getPackageNames(?string $workspace = null): array
    {
        $packages = [];

        $targets = $workspace !== null
            ? [$workspace => (array) $this->manifest->get("workspaces.{$workspace}.packages", [])]
            : $this->getRawPackages();

        foreach ($targets as $items) {
            foreach ($items as $item) {
                $name = is_array($item) ? ($item['name'] ?? null) : (string) $item;
                if ($name !== null && trim($name) !== '') {
                    $packages[] = $name;
                }
            }
        }

        return array_values(array_unique($packages));
    }

    /**
     * Check if a package is registered in specified or any workspace.
     */
    public function hasPackage(string $packageName, ?string $workspace = null): bool
    {
        return in_array($packageName, $this->getPackageNames($workspace), true);
    }

    /**
     * Find workspace name containing the given package.
     */
    public function findPackageWorkspace(string $packageName): ?string
    {
        foreach ($this->getWorkspaces() as $wsKey => $wsConfig) {
            $items = (array) ($wsConfig['packages'] ?? []);
            foreach ($items as $item) {
                $name = is_array($item) ? ($item['name'] ?? null) : (string) $item;
                if ($name === $packageName) {
                    return $wsKey;
                }
            }
        }

        return null;
    }

    /**
     * Get normalized package descriptor.
     *
     * @return array{workspace: string, name: string, alias: ?string, url: ?string, skills: array<string>}|null
     */
    public function getPackage(string $packageName, ?string $workspace = null): ?array
    {
        $workspaces = $workspace !== null
            ? [$workspace => $this->manifest->get("workspaces.{$workspace}")]
            : $this->getWorkspaces();

        foreach ($workspaces as $wsKey => $wsConfig) {
            if (! is_array($wsConfig)) {
                continue;
            }

            $items = (array) ($wsConfig['packages'] ?? []);
            foreach ($items as $item) {
                $name = is_array($item) ? ($item['name'] ?? null) : (string) $item;
                if ($name === $packageName) {
                    return [
                        'workspace' => (string) $wsKey,
                        'name' => $name,
                        'alias' => is_array($item) ? ($item['alias'] ?? null) : null,
                        'url' => is_array($item) ? ($item['url'] ?? null) : null,
                        'skills' => is_array($item) ? (array) ($item['skills'] ?? []) : [],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Add or update a package in a workspace atomically.
     *
     * @param  array<string>  $skills
     */
    public function addPackage(
        string $workspace,
        string $packageName,
        ?string $alias = null,
        ?string $url = null,
        array $skills = []
    ): self {
        $this->manifest->mutate(function (array $data) use ($workspace, $packageName, $alias, $url, $skills): array {
            if (! isset($data['workspaces']) || ! is_array($data['workspaces'])) {
                $data['workspaces'] = [];
            }

            if (! isset($data['workspaces'][$workspace])) {
                $data['workspaces'][$workspace] = [
                    'vendor' => null,
                    'packages' => [],
                ];
            }

            if (($data['default'] ?? null) === null) {
                $data['default'] = $workspace;
            }

            $packages = (array) ($data['workspaces'][$workspace]['packages'] ?? []);
            $newEntry = ($alias === null && $url === null && empty($skills))
                ? $packageName
                : array_filter([
                    'name' => $packageName,
                    'alias' => $alias,
                    'url' => $url,
                    'skills' => ! empty($skills) ? array_values($skills) : null,
                ], fn ($val) => $val !== null);

            $replaced = false;
            foreach ($packages as $index => $item) {
                $existingName = is_array($item) ? ($item['name'] ?? null) : (string) $item;
                if ($existingName === $packageName) {
                    $packages[$index] = $newEntry;
                    $replaced = true;
                    break;
                }
            }

            if (! $replaced) {
                $packages[] = $newEntry;
            }

            $data['workspaces'][$workspace]['packages'] = array_values($packages);

            return $data;
        });

        return $this;
    }

    /**
     * Remove a package from a workspace (or find it automatically if workspace is null).
     */
    public function removePackage(
        string $packageName,
        ?string $workspace = null,
        bool $pruneEmptyWorkspace = false
    ): bool {
        $targetWorkspace = $workspace ?? $this->findPackageWorkspace($packageName);
        if ($targetWorkspace === null) {
            return false;
        }

        $removed = false;

        $this->manifest->mutate(function (array $data) use ($targetWorkspace, $packageName, $pruneEmptyWorkspace, &$removed): array {
            if (! isset($data['workspaces'][$targetWorkspace])) {
                return $data;
            }

            $packages = (array) ($data['workspaces'][$targetWorkspace]['packages'] ?? []);
            $filtered = [];

            foreach ($packages as $item) {
                $existingName = is_array($item) ? ($item['name'] ?? null) : (string) $item;
                if ($existingName === $packageName) {
                    $removed = true;

                    continue;
                }
                $filtered[] = $item;
            }

            $data['workspaces'][$targetWorkspace]['packages'] = array_values($filtered);

            if ($pruneEmptyWorkspace && empty($data['workspaces'][$targetWorkspace]['packages'])) {
                unset($data['workspaces'][$targetWorkspace]);

                if (($data['default'] ?? null) === $targetWorkspace) {
                    $data['default'] = array_key_first($data['workspaces'] ?? []) ?? null;
                }
            }

            return $data;
        });

        return $removed;
    }
}
