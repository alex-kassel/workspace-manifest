<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest;

use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\WorkspaceManifest\DTOs\PackageDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceManifestDto;
use AlexKassel\WorkspaceManifest\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidPackageAliasException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidPackageNameException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidRepositoryUrlTemplateException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidVendorSlugException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceManifest\Exceptions\PackageConflictException;
use AlexKassel\WorkspaceManifest\Exceptions\PackageNotFoundException;
use AlexKassel\WorkspaceManifest\Exceptions\WorkspaceAlreadyExistsException;
use AlexKassel\WorkspaceManifest\Exceptions\WorkspaceNotFoundException;
use AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema;
use AlexKassel\WorkspaceManifest\Validation\WorkspaceValidator;
use Closure;
use InvalidArgumentException;

class WorkspaceManifest
{
    public const DEFAULT_FILENAME = 'workspace.json';

    public const DEFAULT_REPOSITORY_URL_TEMPLATE = 'git@github.com:{package}.git';

    public const DEFAULT_EMPTY_ARRAY = [];

    protected Manifest $manifest;

    protected ?WorkspaceManifestDto $cachedDto = null;

    public function __construct(
        Manifest|string $manifest = self::DEFAULT_FILENAME,
    ) {
        if (is_string($manifest)) {
            $this->manifest = new Manifest($manifest, new WorkspaceSchema);
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

        if (function_exists('base_path') && ! str_starts_with($target, '/') && ! preg_match('/^[a-zA-Z]:[\\\\\/]/', $target)) {
            $target = base_path($target);
        }

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

        if ($trimmed === '' || $trimmed === '.' || $trimmed === './') {
            throw new InvalidWorkspacePathException($path, 'Workspace path cannot be empty or root directory.');
        }

        if (str_starts_with($trimmed, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
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
            if (str_contains($segment, '..')) {
                throw new InvalidWorkspacePathException($path, 'Path traversal ("..") is not allowed.');
            }
            if (strlen($segment) > 255) {
                throw new InvalidWorkspacePathException($path, 'Path segment exceeds maximum filesystem length of 255 characters.');
            }
            if (str_starts_with($segment, '.')) {
                throw new InvalidWorkspacePathException(
                    $path,
                    "Invalid path segment [{$segment}]. Workspace folder names cannot start with a dot."
                );
            }
            if (! preg_match('/^[a-zA-Z0-9@~+_]([a-zA-Z0-9@~+_.\-]*[a-zA-Z0-9@~+_])?$/', $segment)) {
                throw new InvalidWorkspacePathException(
                    $path,
                    "Invalid path segment [{$segment}]. Workspace folder names must start and end with an alphanumeric character or safe prefix and contain only letters, numbers, dashes, underscores, and single dots."
                );
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
     * Clear in-memory DTO cache and invalidate underlying manifest.
     */
    public function clearCache(): self
    {
        $this->cachedDto = null;
        $this->manifest->invalidate();

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
     * Mutate workspace manifest DTO under atomic lock and update DTO cache.
     *
     * @param  Closure(WorkspaceManifestDto): (WorkspaceManifestDto|void)  $mutator
     */
    public function mutateDto(Closure $mutator): WorkspaceManifestDto
    {
        $dto = $this->manifest->mutateDto(WorkspaceManifestDto::class, $mutator);
        $this->cachedDto = $dto;

        return $dto;
    }

    /**
     * Save a typed WorkspaceManifestDto back to the manifest.
     */
    public function saveDto(WorkspaceManifestDto $dto): self
    {
        $this->manifest->save($dto);
        $this->cachedDto = $dto;

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
     * Get default workspace name or throw if not configured.
     *
     * @throws DefaultWorkspaceNotConfiguredException
     */
    public function getRequiredDefaultWorkspace(): string
    {
        $default = $this->getDefaultWorkspace();
        if ($default === null) {
            throw new DefaultWorkspaceNotConfiguredException;
        }

        return $default;
    }

    /**
     * Set default workspace name.
     */
    public function setDefaultWorkspace(?string $workspace): self
    {
        $clean = $workspace !== null ? self::normalizeWorkspacePath($workspace) : null;

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withDefault($clean));

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
     *
     * @throws InvalidRepositoryUrlTemplateException
     */
    public function setRepositoryUrlTemplate(string $template): self
    {
        $result = WorkspaceValidator::validateRepositoryUrlTemplate($template);
        if ($result->isInvalid()) {
            throw new InvalidRepositoryUrlTemplateException($template, $result->errorMessage());
        }

        $cleanTemplate = $result->normalized() ?? trim($template);

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withRepositoryUrlTemplate($cleanTemplate));

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
        return (array) $this->manifest->get('workspaces', self::DEFAULT_EMPTY_ARRAY);
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
     * Get a workspace definition by name.
     */
    public function getWorkspace(string $workspace): ?WorkspaceDefinition
    {
        try {
            $clean = self::normalizeWorkspacePath($workspace);
        } catch (InvalidWorkspacePathException) {
            return null;
        }

        return $this->toDto()->getWorkspace($clean);
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

        return $this->toDto()->hasWorkspace($clean);
    }

    /**
     * Register a new workspace.
     *
     * @throws InvalidWorkspacePathException
     * @throws InvalidVendorSlugException
     * @throws WorkspaceAlreadyExistsException
     */
    public function registerWorkspace(string $workspace, ?string $vendor = null, bool $asDefault = false): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        $vendorResult = WorkspaceValidator::validateVendorSlug($vendor, true);
        if ($vendorResult->isInvalid()) {
            throw new InvalidVendorSlugException(
                vendor: $vendor ?? '',
                suggestion: $vendorResult->suggestion(),
                message: $vendorResult->errorMessage(),
            );
        }

        if ($this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceAlreadyExistsException($cleanWorkspace);
        }

        $cleanVendor = $vendorResult->normalized();

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withWorkspace($cleanWorkspace, $cleanVendor, $asDefault));

        return $this;
    }

    /**
     * Unregister a workspace.
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     */
    public function unregisterWorkspace(string $workspace, bool $reassignDefault = true): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        if (! $this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withoutWorkspace($cleanWorkspace, $reassignDefault));

        return $this;
    }

    /**
     * Remove a workspace from the manifest (alias of unregisterWorkspace).
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     */
    public function removeWorkspace(string $workspace, bool $reassignDefault = true): self
    {
        return $this->unregisterWorkspace($workspace, $reassignDefault);
    }

    /**
     * Get vendor prefix configured for a workspace.
     *
     * @throws InvalidWorkspacePathException
     */
    public function getWorkspaceVendor(string $workspace): ?string
    {
        $clean = self::normalizeWorkspacePath($workspace);

        return $this->toDto()->getWorkspace($clean)?->vendor;
    }

    /**
     * Set or clear vendor prefix configured for a workspace.
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     * @throws InvalidVendorSlugException
     */
    public function setWorkspaceVendor(string $workspace, ?string $vendor): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        if (! $this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        $vendorResult = WorkspaceValidator::validateVendorSlug($vendor, true);
        if ($vendorResult->isInvalid()) {
            throw new InvalidVendorSlugException(
                vendor: $vendor ?? '',
                suggestion: $vendorResult->suggestion(),
                message: $vendorResult->errorMessage(),
            );
        }

        $cleanVendor = $vendorResult->normalized();

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withWorkspaceVendor($cleanWorkspace, $cleanVendor));

        return $this;
    }

    /**
     * Get all raw package entries in given workspace, or all workspaces.
     *
     * @return array<string|int, mixed>
     */
    public function getRawPackages(?string $workspace = null): array
    {
        $workspaces = $this->getWorkspaces();

        if ($workspace !== null) {
            $clean = self::normalizeWorkspacePath($workspace);

            return $workspaces[$clean]['packages'] ?? [];
        }

        $all = [];
        foreach ($workspaces as $wsKey => $wsConfig) {
            $all[$wsKey] = $wsConfig['packages'];
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
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     * @throws InvalidPackageNameException
     * @throws InvalidPackageAliasException
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

        if (! $this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        $wsVendor = $this->getWorkspaceVendor($cleanWorkspace);
        $pkgResult = WorkspaceValidator::validatePackageName($packageName, $wsVendor);
        if ($pkgResult->isInvalid()) {
            throw new InvalidPackageNameException(
                packageName: $packageName,
                suggestion: $pkgResult->suggestion(),
                message: $pkgResult->errorMessage(),
            );
        }

        $cleanAlias = null;
        if ($alias !== null && trim($alias) !== '') {
            $aliasResult = WorkspaceValidator::validatePackageAlias($alias);
            if ($aliasResult->isInvalid()) {
                throw new InvalidPackageAliasException($alias, $aliasResult->errorMessage());
            }
            $cleanAlias = $aliasResult->normalized();
        }

        $cleanUrl = $url !== null && trim($url) !== '' ? trim($url) : null;
        $canonicalName = $pkgResult->canonicalName ?? strtolower(trim($packageName));

        $package = new PackageDefinition(
            name: $canonicalName,
            workspace: $cleanWorkspace,
            alias: $cleanAlias,
            url: $cleanUrl,
            skills: $skills,
        );

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withPackage($cleanWorkspace, $package));

        return $this;
    }

    /**
     * Register or update package alias in a workspace.
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     * @throws InvalidPackageNameException
     * @throws InvalidPackageAliasException
     * @throws PackageConflictException
     * @throws PackageNotFoundException
     */
    public function registerPackageAlias(string $workspace, string $packageName, string $alias): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        if (! $this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        $pkg = $this->getPackage($packageName, $cleanWorkspace);
        if ($pkg === null) {
            throw new PackageNotFoundException($packageName, $cleanWorkspace);
        }

        return $this->addPackage($cleanWorkspace, $packageName, $alias, $pkg->url, $pkg->skills);
    }

    /**
     * Update active skills for a package in workspace.json.
     *
     * @param  array<string>  $skills
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     * @throws InvalidPackageNameException
     * @throws InvalidPackageAliasException
     * @throws PackageConflictException
     * @throws PackageNotFoundException
     */
    public function updatePackageSkills(string $workspace, string $packageName, array $skills): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        if (! $this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        $pkg = $this->getPackage($packageName, $cleanWorkspace);
        if ($pkg === null) {
            throw new PackageNotFoundException($packageName, $cleanWorkspace);
        }

        $skillsResult = WorkspaceValidator::validateSkills($skills);
        if ($skillsResult->isInvalid()) {
            throw new InvalidArgumentException($skillsResult->errorMessage() ?? 'Invalid skills list.');
        }

        /** @var array<string> $validatedSkills */
        $validatedSkills = $skillsResult->normalized() ?? [];

        return $this->addPackage($cleanWorkspace, $packageName, $pkg->alias, $pkg->url, $validatedSkills);
    }

    /**
     * Save a typed PackageDefinition into the manifest.
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     * @throws PackageConflictException
     */
    public function savePackage(PackageDefinition $package): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($package->workspace);

        if (! $this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->savePackage($package));

        return $this;
    }

    /**
     * Save a typed WorkspaceDefinition into the manifest.
     *
     * @throws InvalidWorkspacePathException
     */
    public function saveWorkspace(WorkspaceDefinition $workspace, bool $asDefault = false): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace->name);

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withWorkspaceDefinition(
            new WorkspaceDefinition(
                name: $cleanWorkspace,
                vendor: $workspace->vendor,
                packages: $workspace->packages,
                hooks: $workspace->hooks,
            ),
            $asDefault
        ));

        return $this;
    }

    /**
     * Get lifecycle hooks configured for a workspace.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     */
    public function getWorkspaceHooks(string $workspace): array
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        $ws = $this->toDto()->getWorkspace($cleanWorkspace);
        if ($ws === null) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        return $ws->hooks ?? [];
    }

    /**
     * Set or update a lifecycle hook for a workspace.
     *
     * @param  string|array<int, string>  $command
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     */
    public function setWorkspaceHook(string $workspace, string $hook, string|array $command): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        if (! $this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        $cleanHook = trim($hook);
        if ($cleanHook === '') {
            throw new InvalidArgumentException('Hook name cannot be empty.');
        }

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withWorkspaceHook($cleanWorkspace, $cleanHook, $command));

        return $this;
    }

    /**
     * Remove a lifecycle hook from a workspace.
     *
     * @throws InvalidWorkspacePathException
     * @throws WorkspaceNotFoundException
     */
    public function removeWorkspaceHook(string $workspace, string $hook): self
    {
        $cleanWorkspace = self::normalizeWorkspacePath($workspace);

        if (! $this->hasWorkspace($cleanWorkspace)) {
            throw new WorkspaceNotFoundException($cleanWorkspace);
        }

        $cleanHook = trim($hook);

        $this->mutateDto(static fn (WorkspaceManifestDto $dto): WorkspaceManifestDto => $dto->withoutWorkspaceHook($cleanWorkspace, $cleanHook));

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
        $cleanPackageName = strtolower(trim($packageName));
        $explicitWorkspace = $workspace !== null ? self::normalizeWorkspacePath($workspace) : null;

        $removed = false;

        $this->mutateDto(function (WorkspaceManifestDto $dto) use ($explicitWorkspace, $cleanPackageName, $pruneEmptyWorkspace, &$removed): WorkspaceManifestDto {
            $targetWorkspace = $explicitWorkspace ?? $dto->findPackageWorkspace($cleanPackageName);
            if ($targetWorkspace === null) {
                return $dto;
            }

            [$newDto, $wasRemoved] = $dto->withoutPackage($cleanPackageName, $targetWorkspace, $pruneEmptyWorkspace);
            $removed = $wasRemoved;

            return $newDto;
        });

        return $removed;
    }
}
