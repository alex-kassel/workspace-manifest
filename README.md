# Workspace Manifest

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alex-kassel/workspace-manifest.svg?style=flat-square)](https://packagist.org/packages/alex-kassel/workspace-manifest)
[![Total Downloads](https://img.shields.io/packagist/dt/alex-kassel/workspace-manifest.svg?style=flat-square)](https://packagist.org/packages/alex-kassel/workspace-manifest)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

A clean, strongly-typed domain manifest model, repository, and schema validator for managing `workspace.json` configurations in modular and multi-package Laravel ecosystems. Built directly on top of [`alex-kassel/manifest-engine`](https://github.com/alex-kassel/manifest-engine).

---

## Key Features

- 🔒 **Atomic & Process-Safe**: Advisory file locks (`flock(LOCK_EX)`) and atomic temp-file-to-target replacement eliminate race conditions during concurrent CLI executions.
- 📐 **Strict Schema Validation**: Validates `workspace.json` structure via `WorkspaceSchema` with Draft-07 JSON Schema export for IDE autocomplete.
- 📦 **Strongly-Typed DTOs**: Inspect packages through `PackageDefinition` and workspaces through `WorkspaceDefinition` with helpers for canonical vendor names, directory paths, and aliases.
- 🛡️ **Path Normalization & Conflict Prevention**: Prevents path-traversal attacks (`..`) and strictly blocks alias-name collisions across registered packages (Rule F-03).
- ⚡ **Standalone Zero-Dependency Runner**: Scaffold an executable `./workspace` CLI tool via `artisan workspace-manifest:install` to restore/clone missing packages on a clean machine before running `composer install`.

---

## Installation

Install via Composer:

```bash
composer require alex-kassel/workspace-manifest
```

---

## Workspace Specification (`workspace.json`)

A standard `workspace.json` document looks like:

```json
{
    "$schema": "https://raw.githubusercontent.com/alex-kassel/workspace-manifest/main/resources/schema.json",
    "default": "packages",
    "repository_url_template": "git@github.com:{package}.git",
    "workspaces": {
        "packages": {
            "vendor": "alex-kassel",
            "packages": [
                "alex-kassel/manifest-engine",
                {
                    "name": "alex-kassel/workspace-manifest",
                    "alias": "WorkspaceManifest",
                    "skills": [
                        "package-docs",
                        "package-audit"
                    ]
                }
            ]
        }
    }
}
```

> **IDE Autocomplete & Diagnostics**: Referencing `resources/schema.json` via `$schema` activates real-time autocompletion (`Ctrl+Space`), inline property docs, and syntax diagnostics in PhpStorm and VS Code.

---

## Quick Start

### Basic Usage

```php
use AlexKassel\WorkspaceManifest\WorkspaceManifest;

$manifest = WorkspaceManifest::open(base_path('workspace.json'));

// 1. Register a workspace
$manifest->registerWorkspace('packages', vendor: 'acme', asDefault: true);

// 2. Add packages (automatic deduplication and alphabetical sorting)
$manifest->addPackage('packages', 'acme/billing');

// 3. Add rich package descriptor with custom metadata
$manifest->addPackage(
    workspace: 'packages',
    packageName: 'acme/auth',
    alias: 'AuthModule',
    url: 'git@github.com:acme/auth.git',
    skills: ['testing-best-practices']
);

// 4. Query packages via strongly-typed DTO
$pkg = $manifest->getPackage('acme/auth');
if ($pkg !== null) {
    echo $pkg->canonicalName();       // "acme/auth"
    echo $pkg->effectiveDirectory();  // "AuthModule"
    echo $pkg->isAliased();           // true
}

// 5. Update package skills or alias
$manifest->updatePackageSkills('packages', 'acme/auth', ['laravel-best-practices', 'testing']);
$manifest->registerPackageAlias('packages', 'acme/auth', 'NewAuthAlias');

// 6. Remove packages
$manifest->removePackage('acme/billing', pruneEmptyWorkspace: false);
```

### Laravel Facade Usage

```php
use AlexKassel\WorkspaceManifest\Facades\WorkspaceManifest;

WorkspaceManifest::addPackage('packages', 'acme/analytics');
$packages = WorkspaceManifest::getPackageNames('packages');
$definitions = WorkspaceManifest::getWorkspaceDefinitions();
```

---

## API Reference

### Workspace Operations

- `toDto(): WorkspaceManifestDto`
- `saveDto(WorkspaceManifestDto $dto): self`
- `getDefaultWorkspace(): ?string`
- `setDefaultWorkspace(?string $workspace): self`
- `getRepositoryUrlTemplate(): string`
- `setRepositoryUrlTemplate(string $template): self`
- `getWorkspaces(): array`
- `getWorkspaceNames(): array<string>`
- `getWorkspaceDefinitions(): array<string, WorkspaceDefinition>`
- `hasWorkspace(string $workspace): bool`
- `registerWorkspace(string $workspace, ?string $vendor = null, bool $asDefault = false): self`
- `unregisterWorkspace(string $workspace, bool $reassignDefault = true): self`
- `getWorkspaceVendor(string $workspace): ?string`
- `setWorkspaceVendor(string $workspace, ?string $vendor): self`
- `normalizeWorkspacePath(string $path): string`

### Package Operations

- `hasPackage(string $packageName, ?string $workspace = null): bool`
- `findPackageWorkspace(string $packageName): ?string`
- `getPackage(string $packageName, ?string $workspace = null): ?PackageDefinition`
- `getPackageNames(?string $workspace = null): array<string>`
- `getRawPackages(?string $workspace = null): array`
- `addPackage(string $workspace, string $packageName, ?string $alias = null, ?string $url = null, array $skills = []): self`
- `registerPackageAlias(string $workspace, string $packageName, string $alias): self`
- `updatePackageSkills(string $workspace, string $packageName, array $skills): self`
- `removePackage(string $packageName, ?string $workspace = null, bool $pruneEmptyWorkspace = false): bool`

### Standalone Runner (`./workspace`)

The package publishes a zero-dependency CLI runner to the host root via `php artisan workspace-manifest:install`:

```bash
# Clone all missing workspace packages defined in workspace.json
php workspace restore

# Check status and physical presence of packages
php workspace status
```

---

## Testing & Quality

Run the test suite and static analysis:

```bash
vendor/bin/phpunit packages/alex-kassel/workspace-manifest/tests
vendor/bin/phpstan analyse -c packages/alex-kassel/workspace-manifest/phpstan.neon
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
