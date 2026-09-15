<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest;

use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\WorkspaceManifest\DTOs\PackageDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceManifestDto;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceManifest\Exceptions\PackageConflictException;
use AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema;

class WorkspaceManifest
{
    public const DEFAULT_FILENAME = 'workspace.json';

    public const DEFAULT_REPOSITORY_URL_TEMPLATE = 'git@github.com:{package}.git';

    public const PATH_SEGMENT_REGEX = '/^[a-zA-Z0-9]([a-zA-Z0-9_\-\.]*[a-zA-Z0-9])?$/';

    public const MAX_PATH_SEGMENT_LENGTH = 255;

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
     * Normalize and validate workspace path.
     * Prevents path traversal, absolute paths, and invalid segment characters.
     *
     * @throws InvalidWorkspacePathException
     */
    public static function normalizeWorkspacePath(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '' || $trimmed === '.' || $trimmed === './') {
            throw new InvalidWorkspacePathException($path, 'Workspace path cannot be empty or root directory.');
        }

        $normalized = str_replace('\\', '/', $trimmed);

        if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $trimmed)) {
            throw new InvalidWorkspacePathException($path, 'Absolute paths are not allowed. Workspace must be a relative path.');
        }

        $cleanPath = trim((string) preg_replace('#/+#', '/', $normalized), '/');
        $rawSegments = explode('/', $cleanPath);
        $segments = [];

        foreach ($rawSegments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || str_contains($segment, '..')) {
                throw new InvalidWorkspacePathException($path, 'Path traversal ("..") is not allowed.');
            }
            if (strlen($segment) > self::MAX_PATH_SEGMENT_LENGTH) {
                throw new InvalidWorkspacePathException($path, 'Path segment exceeds maximum length of 255 characters.');
            }
            if (! preg_match(self::PATH_SEGMENT_REGEX, $segment)) {
                throw new InvalidWorkspacePathException(
                    $path,
                    "Invalid path segment [{$segment}]. Folder names must start and end with an alphanumeric character and contain only letters, numbers, dashes, underscores, and single dots."
                );
            }
            $segments[] = $segment;
        }

        if (empty($segments)) {
            throw new InvalidWorkspacePathException($path, 'Workspace path cannot be empty or root directory.');
        }

        return implode('/', $segments);
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
     * Hydrate the workspace manifest into a typed WorkspaceManifestDto.
     */
    public function toDto(): WorkspaceManifestDto
    {
        return $this->manifest->toDto(WorkspaceManifestDto::class);
    }

    /**
     * Save a typed WorkspaceManifestDto back to the manifest.
     */
    public function saveDto(WorkspaceManifestDto $dto): self
    {
        $this->manifest->saveDto($dto);

        return $this;
    }

    /**
     * Get default workspace name.
     */
    public function getDefaultWorkspace(): ?string
    {
        $default = $this->manifest->get('default');

        return is_string($default) && $default !== '' ? $default : null;
    }

    /**
     * Set default workspace name.
     */
    public function setDefaultWorkspace(?string $workspace): self
    {
        $clean = $workspace !== null ? self::normalizeWorkspacePath($workspace) : null;
        $this->manifest->set('default', $clean);

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
     * Get all raw workspaces configuration.
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
     * Get strongly-typed workspace definitions.
     *
     * @return array<string, WorkspaceDefinition>
     */
    public function getWorkspaceDefinitions(): array
    {
        return $this->toDto()->workspaces;
    }

    /**
     * Determine if a workspace is registered.
     */
    public function hasWorkspace(string $workspace): bool
    {
        try {
            $clean = self::normalizeWorkspacePath($workspace);
        } catch (InvalidWorkspacePathException) {
            return false;
        }

        return $this->manifest->has("workspaces.{$clean}");
    }

    /**
     * Register a new workspace.
     */
    public function registerWorkspace(string $workspace, ?string $vendor = null, bool $asDefault = false): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);
        $cleanVendor = $vendor !== null ? strtolower(trim($vendor)) : null;

        $this->manifest->mutate(function (array $data) use ($cleanWorkspace, $cleanVendor, $asDefault): array {
            if (! isset($data['workspaces']) || ! is_array($data['workspaces'])) {
                $data['workspaces'] = [];
            }

            if (! isset($data['workspaces'][$cleanWorkspace])) {
                $data['workspaces'][$cleanWorkspace] = [
                    'vendor' => $cleanVendor,
                    'packages' => [],
                ];
            } elseif ($cleanVendor !== null) {
                $data['workspaces'][$cleanWorkspace]['vendor'] = $cleanVendor;
            }

            ksort($data['workspaces']);

            if ($asDefault || ($data['default'] ?? null) === null) {
                $data['default'] = $cleanWorkspace;
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
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        $this->manifest->mutate(function (array $data) use ($cleanWorkspace, $reassignDefault): array {
            if (isset($data['workspaces'][$cleanWorkspace])) {
                unset($data['workspaces'][$cleanWorkspace]);
            }

            if ($reassignDefault && ($data['default'] ?? null) === $cleanWorkspace) {
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
        $clean = self::normalizeWorkspacePath($workspace);

        return $this->manifest->get("workspaces.{$clean}.vendor");
    }

    /**
     * Set or clear vendor prefix configured for a workspace.
     */
    public function setWorkspaceVendor(string $workspace, ?string $vendor): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);
        $cleanVendor = $vendor !== null ? strtolower(trim($vendor)) : null;

        $this->manifest->mutate(function (array $data) use ($cleanWorkspace, $cleanVendor): array {
            if (! isset($data['workspaces'][$cleanWorkspace])) {
                $data['workspaces'][$cleanWorkspace] = [
                    'vendor' => $cleanVendor,
                    'packages' => [],
                ];
            } else {
                $data['workspaces'][$cleanWorkspace]['vendor'] = $cleanVendor;
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
            $clean = self::normalizeWorkspacePath($workspace);

            return (array) $this->manifest->get("workspaces.{$clean}.packages", []);
        }

        $all = [];
        foreach ($this->getWorkspaces() as $wsKey => $wsConfig) {
            $all[$wsKey] = (array) $wsConfig['packages'];
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
        try {
            $cleanWorkspace = $workspace !== null ? self::normalizeWorkspacePath($workspace) : null;
        } catch (InvalidWorkspacePathException) {
            return [];
        }

        return $this->toDto()->packageNames($cleanWorkspace);
    }

    /**
     * Check if a package is registered in specified or any workspace.
     * Supports matching canonical names or local names.
     */
    public function hasPackage(string $packageName, ?string $workspace = null): bool
    {
        return $this->getPackage($packageName, $workspace) !== null;
    }

    /**
     * Find workspace name containing the given package.
     */
    public function findPackageWorkspace(string $packageName): ?string
    {
        return $this->toDto()->findPackageWorkspace($packageName);
    }

    /**
     * Get normalized package descriptor as a strongly-typed PackageDefinition DTO.
     */
    public function getPackage(string $packageName, ?string $workspace = null): ?PackageDefinition
    {
        try {
            $cleanWorkspace = $workspace !== null ? self::normalizeWorkspacePath($workspace) : null;
        } catch (InvalidWorkspacePathException) {
            return null;
        }

        return $this->toDto()->findPackage($packageName, $cleanWorkspace);
    }

    /**
     * Add or update a package in a workspace atomically.
     * Enforces conflict rule F-03 and alphabetical sorting.
     *
     * @param  array<string>  $skills
     *
     * @throws PackageConflictException
     */
    public function addPackage(
        string $workspace,
        string $packageName,
        ?string $alias = null,
        ?string $url = null,
        array $skills = []
    ): self {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        $this->manifest->mutate(function (array $data) use ($cleanWorkspace, $packageName, $alias, $url, $skills): array {
            if (! isset($data['workspaces']) || ! is_array($data['workspaces'])) {
                $data['workspaces'] = [];
            }

            if (! isset($data['workspaces'][$cleanWorkspace])) {
                $data['workspaces'][$cleanWorkspace] = [
                    'vendor' => null,
                    'packages' => [],
                ];
            }

            if (($data['default'] ?? null) === null) {
                $data['default'] = $cleanWorkspace;
            }

            $packages = (array) ($data['workspaces'][$cleanWorkspace]['packages'] ?? []);

            // Conflict validation across existing packages in the workspace
            foreach ($packages as $item) {
                $existingName = is_array($item) ? ($item['name'] ?? '') : (string) $item;
                $existingAlias = is_array($item) ? ($item['alias'] ?? null) : null;

                if (strcasecmp($existingName, $packageName) !== 0) {
                    if ($existingAlias !== null && strcasecmp($existingAlias, $packageName) === 0) {
                        throw new PackageConflictException(
                            $packageName,
                            $existingName,
                            "Cannot record package [{$packageName}]: it conflicts with the alias of existing package [{$existingName}]."
                        );
                    }

                    if ($alias !== null) {
                        if (strcasecmp($existingName, $alias) === 0) {
                            throw new PackageConflictException(
                                $alias,
                                $existingName,
                                "Cannot use alias [{$alias}]: it conflicts with the name of existing package [{$existingName}]."
                            );
                        }

                        if ($existingAlias !== null && strcasecmp($existingAlias, $alias) === 0) {
                            throw new PackageConflictException(
                                $alias,
                                $existingName,
                                "Cannot use alias [{$alias}]: it conflicts with the alias of existing package [{$existingName}]."
                            );
                        }
                    }
                }
            }

            $newEntry = ($alias === null && $url === null && empty($skills))
                ? $packageName
                : array_filter([
                    'name' => $packageName,
                    'alias' => $alias,
                    'url' => $url,
                    'skills' => ! empty($skills) ? array_values(array_unique($skills)) : null,
                ], fn ($val) => $val !== null);

            $replaced = false;
            foreach ($packages as $index => $item) {
                $existingName = is_array($item) ? ($item['name'] ?? '') : (string) $item;
                if (strcasecmp($existingName, $packageName) === 0) {
                    $packages[$index] = $newEntry;
                    $replaced = true;
                    break;
                }
            }

            if (! $replaced) {
                $packages[] = $newEntry;
            }

            // Alphabetical sort by effective directory/alias
            usort($packages, function ($a, $b) {
                $nameA = is_array($a) ? ($a['alias'] ?? $a['name']) : $a;
                $nameB = is_array($b) ? ($b['alias'] ?? $b['name']) : $b;

                return strcasecmp((string) $nameA, (string) $nameB);
            });

            $data['workspaces'][$cleanWorkspace]['packages'] = $packages;

            return $data;
        });

        return $this;
    }

    /**
     * Register or update package alias in a workspace.
     */
    public function registerPackageAlias(string $workspace, string $packageName, string $alias): self
    {
        $pkg = $this->getPackage($packageName, $workspace);
        $url = $pkg?->url;
        $skills = $pkg !== null ? $pkg->skills : [];

        return $this->addPackage($workspace, $packageName, $alias, $url, $skills);
    }

    /**
     * Update active skills for a package in workspace.json.
     *
     * @param  array<string>  $skills
     */
    public function updatePackageSkills(string $workspace, string $packageName, array $skills): self
    {
        $pkg = $this->getPackage($packageName, $workspace);
        $alias = $pkg?->alias;
        $url = $pkg?->url;

        return $this->addPackage($workspace, $packageName, $alias, $url, $skills);
    }

    /**
     * Remove a package from a workspace (or find it automatically if workspace is null).
     */
    public function removePackage(
        string $packageName,
        ?string $workspace = null,
        bool $pruneEmptyWorkspace = false
    ): bool {
        $targetWorkspace = $workspace !== null
            ? self::normalizeWorkspacePath($workspace)
            : $this->findPackageWorkspace($packageName);

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
                $existingName = is_array($item) ? ($item['name'] ?? '') : (string) $item;
                $existingAlias = is_array($item) ? ($item['alias'] ?? null) : null;

                if (strcasecmp($existingName, $packageName) === 0 || ($existingAlias !== null && strcasecmp($existingAlias, $packageName) === 0)) {
                    $removed = true;

                    continue;
                }
                $filtered[] = $item;
            }

            $data['workspaces'][$targetWorkspace]['packages'] = $filtered;

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
