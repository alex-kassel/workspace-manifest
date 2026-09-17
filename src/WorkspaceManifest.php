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
use Closure;

class WorkspaceManifest
{
    public const DEFAULT_FILENAME = 'workspace.json';

    public const DEFAULT_REPOSITORY_URL_TEMPLATE = 'git@github.com:{package}.git';

    protected Manifest $manifest;

    protected ?WorkspaceManifestDto $cachedDto = null;

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
     * Open a WorkspaceManifest instance for given path or configured default.
     */
    public static function open(?string $path = null): self
    {
        $target = $path ?? config('workspace-manifest.path') ?? self::DEFAULT_FILENAME;

        return new self($target);
    }

    /**
     * Normalize and validate workspace path.
     * Prevents path traversal, absolute paths, and empty paths.
     *
     * @throws InvalidWorkspacePathException
     */
    public static function normalizeWorkspacePath(string $path): string
    {
        $trimmed = trim(str_replace('\\', '/', $path));

        if ($trimmed === '' || str_starts_with($trimmed, '/') || preg_match('/^[a-zA-Z]:\//', $trimmed)) {
            throw new InvalidWorkspacePathException($path, 'Absolute paths are not allowed. Workspace must be a relative path.');
        }

        $parts = [];
        foreach (explode('/', $trimmed) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($parts)) {
                    throw new InvalidWorkspacePathException($path, 'Path traversal ("..") is not allowed.');
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        if (empty($parts)) {
            throw new InvalidWorkspacePathException($path, 'Workspace path cannot be empty or root directory.');
        }

        return implode('/', $parts);
    }

    /**
     * Get underlying ManifestEngine instance.
     */
    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    /**
     * Get absolute path to the manifest file.
     */
    public function getPath(): string
    {
        return $this->manifest->path;
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
        $this->cachedDto = null;
        $this->manifest->init();

        return $this;
    }

    /**
     * Clear in-memory DTO cache.
     */
    public function clearCache(): self
    {
        $this->cachedDto = null;

        return $this;
    }

    /**
     * Hydrate the workspace manifest into a typed WorkspaceManifestDto.
     */
    public function toDto(): WorkspaceManifestDto
    {
        return $this->cachedDto ??= $this->manifest->toDto(WorkspaceManifestDto::class);
    }

    /**
     * Save a typed WorkspaceManifestDto back to the manifest.
     */
    public function saveDto(WorkspaceManifestDto $dto): self
    {
        $this->manifest->saveDto($dto);
        $this->cachedDto = $dto;

        return $this;
    }

    /**
     * Mutate underlying manifest data under atomic lock and invalidate DTO cache.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $callback
     */
    protected function mutate(Closure $callback): self
    {
        $this->cachedDto = null;
        $this->manifest->mutate($callback);

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

        $this->mutate(function (array $data) use ($clean): array {
            return WorkspaceManifestDto::fromArray($data)
                ->withDefault($clean)
                ->toArray();
        });

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
        $cleanTemplate = trim($template);

        $this->mutate(function (array $data) use ($cleanTemplate): array {
            return WorkspaceManifestDto::fromArray($data)
                ->withRepositoryUrlTemplate($cleanTemplate)
                ->toArray();
        });

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

        $this->mutate(function (array $data) use ($cleanWorkspace, $vendor, $asDefault): array {
            return WorkspaceManifestDto::fromArray($data)
                ->withWorkspace($cleanWorkspace, $vendor, $asDefault)
                ->toArray();
        });

        return $this;
    }

    /**
     * Unregister a workspace.
     */
    public function unregisterWorkspace(string $workspace, bool $reassignDefault = true): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        $this->mutate(function (array $data) use ($cleanWorkspace, $reassignDefault): array {
            return WorkspaceManifestDto::fromArray($data)
                ->withoutWorkspace($cleanWorkspace, $reassignDefault)
                ->toArray();
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

        $this->mutate(function (array $data) use ($cleanWorkspace, $vendor): array {
            return WorkspaceManifestDto::fromArray($data)
                ->withWorkspaceVendor($cleanWorkspace, $vendor)
                ->toArray();
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
        $package = new PackageDefinition(
            name: strtolower(trim($packageName)),
            workspace: $cleanWorkspace,
            alias: $alias !== null && trim($alias) !== '' ? trim($alias) : null,
            url: $url !== null && trim($url) !== '' ? trim($url) : null,
            skills: $skills,
        );

        $this->mutate(function (array $data) use ($cleanWorkspace, $package): array {
            return WorkspaceManifestDto::fromArray($data)
                ->withPackage($cleanWorkspace, $package)
                ->toArray();
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
        $cleanPackageName = strtolower(trim($packageName));

        $targetWorkspace = $workspace !== null
            ? self::normalizeWorkspacePath($workspace)
            : $this->findPackageWorkspace($cleanPackageName);

        if ($targetWorkspace === null) {
            return false;
        }

        $removed = false;

        $this->mutate(function (array $data) use ($targetWorkspace, $cleanPackageName, $pruneEmptyWorkspace, &$removed): array {
            $dto = WorkspaceManifestDto::fromArray($data);
            [$newDto, $wasRemoved] = $dto->withoutPackage($cleanPackageName, $targetWorkspace, $pruneEmptyWorkspace);
            $removed = $wasRemoved;

            return $newDto->toArray();
        });

        return $removed;
    }
}
