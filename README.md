# Workspace Manifest

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alex-kassel/workspace-manifest.svg?style=flat-square)](https://packagist.org/packages/alex-kassel/workspace-manifest)
[![Total Downloads](https://img.shields.io/packagist/dt/alex-kassel/workspace-manifest.svg?style=flat-square)](https://packagist.org/packages/alex-kassel/workspace-manifest)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

A clean domain model, repository, and schema validator for managing `workspace.json` manifests in modular and multi-package Laravel ecosystems. Built directly on top of [`alex-kassel/manifest-engine`](https://github.com/alex-kassel/manifest-engine).

---

## Key Features

- 🔒 **Atomic & Process-Safe**: Inherits advisory write locks (`flock(LOCK_EX)`) from `manifest-engine`, eliminating race conditions when multiple CLI processes update the manifest simultaneously.
- 📐 **Strict Schema Validation**: Validates `workspace.json` structure via `WorkspaceSchema`, rejecting invalid package shapes and corrupted configuration.
- 📦 **Rich Package Descriptors**: Supports both simple package strings (`vendor/package`) and rich metadata objects (`{ "name": "vendor/package", "alias": "MyPkg", "skills": [...] }`).
- ⚡ **Seamless Workspace Management**: Register, query, update default paths, and unregister workspaces with automatic pruning and reassigning.
- 🎯 **Zero Friction API**: High-level CRUD operations with built-in deduplication.

---

## Installation

Install via Composer:

```bash
composer require alex-kassel/workspace-manifest
```

---

## Workspace Specification (`workspace.json`)

A standard `workspace.json` document managed by this library looks like:

```json
{
    "$schema": "./packages/alex-kassel/workspace-manifest/resources/schema.json",
    "default": "packages",
    "repository_url_template": "git@github.com:{package}.git",
    "workspaces": {
        "packages": {
            "vendor": "alex-kassel",
            "packages": [
                "alex-kassel/manifest-engine",
                {
                    "name": "alex-kassel/workspace-development-toolkit",
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

> **IDE Autocomplete & Linting**: Referencing `resources/schema.json` via `$schema` activates full real-time autocompletion (`Ctrl+Space`), inline property docs, and syntax diagnostics in PhpStorm and VS Code!

---

## Quick Start

### Basic Usage

```php
use AlexKassel\WorkspaceManifest\WorkspaceManifest;

$manifest = WorkspaceManifest::open(base_path('workspace.json'));

// 1. Check & register workspaces
if (! $manifest->hasWorkspace('packages')) {
    $manifest->registerWorkspace('packages', vendor: 'my-org', asDefault: true);
}

// 2. Add packages (automatic deduplication)
$manifest->addPackage('packages', 'my-org/billing');

// 3. Add rich package descriptor with custom metadata
$manifest->addPackage(
    workspace: 'packages',
    packageName: 'my-org/auth',
    alias: 'AuthModule',
    url: 'git@github.com:my-org/auth.git',
    skills: ['testing-best-practices']
);

// 4. Query packages
if ($manifest->hasPackage('my-org/billing')) {
    $workspace = $manifest->findPackageWorkspace('my-org/billing'); // 'packages'
    $package = $manifest->getPackage('my-org/auth');
    // returns: ['workspace' => 'packages', 'name' => 'my-org/auth', 'alias' => 'AuthModule', ...]
}

// 5. Remove packages
$manifest->removePackage('my-org/billing', pruneEmptyWorkspace: false);
```

### Laravel Facade Usage

```php
use AlexKassel\WorkspaceManifest\Facades\WorkspaceManifest;

WorkspaceManifest::addPackage('packages', 'acme/analytics');
$packages = WorkspaceManifest::getPackageNames('packages');
```

---

## API Reference

### Workspace Operations

- `getDefaultWorkspace(): ?string`
- `setDefaultWorkspace(?string $workspace): self`
- `getRepositoryUrlTemplate(): string`
- `setRepositoryUrlTemplate(string $template): self`
- `getWorkspaces(): array`
- `getWorkspaceNames(): array<string>`
- `hasWorkspace(string $workspace): bool`
- `registerWorkspace(string $workspace, ?string $vendor = null, bool $asDefault = false): self`
- `unregisterWorkspace(string $workspace, bool $reassignDefault = true): self`
- `getWorkspaceVendor(string $workspace): ?string`
- `setWorkspaceVendor(string $workspace, ?string $vendor): self`

### Package Operations

- `hasPackage(string $packageName, ?string $workspace = null): bool`
- `findPackageWorkspace(string $packageName): ?string`
- `getPackage(string $packageName, ?string $workspace = null): ?array`
- `getPackageNames(?string $workspace = null): array<string>`
- `getRawPackages(?string $workspace = null): array`
- `addPackage(string $workspace, string $packageName, ?string $alias = null, ?string $url = null, array $skills = []): self`
- `removePackage(string $packageName, ?string $workspace = null, bool $pruneEmptyWorkspace = false): bool`

### Low-Level Access

- `manifest(): Manifest`: Access the underlying `AlexKassel\ManifestEngine\Manifest` instance for raw dot-notation access or custom transaction mutations.
- `init(): self`: Initialize default schema structure if file does not exist.
- `exists(): bool`: Determine if the file exists on disk.

---

## Testing

```bash
composer test
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
