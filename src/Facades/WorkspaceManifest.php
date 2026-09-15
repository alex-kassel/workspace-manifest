<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Facades;

use AlexKassel\WorkspaceManifest\DTOs\PackageDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest open(string $path = 'workspace.json')
 * @method static string normalizeWorkspacePath(string $path)
 * @method static ?string getDefaultWorkspace()
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest setDefaultWorkspace(?string $workspace)
 * @method static string getRepositoryUrlTemplate()
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest setRepositoryUrlTemplate(string $template)
 * @method static array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<string>}>}> getWorkspaces()
 * @method static array<int, string> getWorkspaceNames()
 * @method static array<string, WorkspaceDefinition> getWorkspaceDefinitions()
 * @method static bool hasWorkspace(string $workspace)
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest registerWorkspace(string $workspace, ?string $vendor = null, bool $asDefault = false)
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest unregisterWorkspace(string $workspace, bool $reassignDefault = true)
 * @method static ?string getWorkspaceVendor(string $workspace)
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest setWorkspaceVendor(string $workspace, ?string $vendor)
 * @method static array<int, string> getPackageNames(?string $workspace = null)
 * @method static bool hasPackage(string $packageName, ?string $workspace = null)
 * @method static ?string findPackageWorkspace(string $packageName)
 * @method static ?PackageDefinition getPackage(string $packageName, ?string $workspace = null)
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest addPackage(string $workspace, string $packageName, ?string $alias = null, ?string $url = null, array<string> $skills = [])
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest registerPackageAlias(string $workspace, string $packageName, string $alias)
 * @method static \AlexKassel\WorkspaceManifest\WorkspaceManifest updatePackageSkills(string $workspace, string $packageName, array<string> $skills)
 * @method static bool removePackage(string $packageName, ?string $workspace = null, bool $pruneEmptyWorkspace = false)
 *
 * @see \AlexKassel\WorkspaceManifest\WorkspaceManifest
 */
class WorkspaceManifest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AlexKassel\WorkspaceManifest\WorkspaceManifest::class;
    }
}
