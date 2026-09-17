# Workspace & Package Domain Models Guide

This document describes the typed domain models and Data Transfer Objects (DTOs) that represent multi-package workspace configurations in `alex-kassel/workspace-manifest`.

---

## 1. Overview & Hierarchy

A `workspace.json` file is represented in PHP as an object graph of immutable, strongly-typed DTOs implementing Laravel's `Illuminate\Contracts\Support\Arrayable`:

```text
WorkspaceManifestDto (implements Arrayable, ManifestDto)
  ├── $schema (string|null)
  ├── default (string|null)
  ├── repositoryUrlTemplate (string)
  ├── extra (array)
  └── workspaces (array<string, WorkspaceDefinition>)
        ├── name (string)
        ├── vendor (string|null)
        ├── hooks (array|null)
        └── packages (array<int, PackageDefinition>)
              ├── name (string - ALWAYS canonical "vendor/package")
              ├── workspace (string)
              ├── alias (string|null)
              ├── url (string|null)
              └── skills (array<string>)
```

---

## 2. Root Model: `WorkspaceManifestDto`

`AlexKassel\WorkspaceManifest\DTOs\WorkspaceManifestDto` represents the root manifest document. It implements `ManifestDto` and `Arrayable`.

### Properties:
* `schema`: Path or URL to the JSON Schema.
* `default`: The default workspace directory (e.g. `"packages"`).
* `repositoryUrlTemplate`: Remote Git repository URL template (e.g. `"git@github.com:{package}.git"`).
* `workspaces`: Associative map of `[workspaceName => WorkspaceDefinition]`.
* `extra`: Unmapped custom root-level properties preserved for forward compatibility.

### Query Methods:
```php
use AlexKassel\WorkspaceManifest\Facades\WorkspaceManifest;

$dto = WorkspaceManifest::toDto();

// Check if workspace is registered
$exists = $dto->hasWorkspace('packages');

// Retrieve a workspace definition
$ws = $dto->getWorkspace('packages');

// Find a package across any workspace by canonical name, short name, or alias
$pkg = $dto->findPackage('billing');

// Find which workspace contains a package
$wsName = $dto->findPackageWorkspace('acme/billing'); // e.g. "packages"

// Get all canonical package names across all workspaces
$allNames = $dto->packageNames();
```

### Global Cross-Workspace Conflict Prevention (Rule F-03):
When adding packages via `$dto->withPackage($workspace, $package)`, `WorkspaceManifestDto` enforces conflict detection across **all** registered workspaces:
- If a package with the same canonical name already exists in any workspace, a `PackageConflictException` is thrown.
- If a package declares an `alias` matching any existing package name or alias anywhere in the project, a `PackageConflictException` is thrown.

---

## 3. Workspace Model: `WorkspaceDefinition`

`AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition` represents an individual workspace directory on disk. It implements `Illuminate\Contracts\Support\Arrayable`.

### Fixed-Vendor vs. Multi-Vendor Workspaces:
* **Fixed-Vendor Workspaces (`vendor !== null`):**
  When a workspace specifies a vendor (e.g. `"vendor": "acme"`), packages belonging to that vendor are persisted as short slugs (e.g. `"billing"`). However, at runtime in PHP, they are **always inflated into full canonical names** (`"acme/billing"`):
  ```json
  "packages": {
    "vendor": "acme",
    "packages": ["billing", "auth"]
  }
  ```
* **Multi-Vendor / Vendor-less Workspaces (`vendor === null`):**
  Packages explicitly specify their full vendor prefix in the JSON file:
  ```json
  "third-party": {
    "vendor": null,
    "packages": ["spatie/laravel-ray", "barryvdh/laravel-debugbar"]
  }
  ```

### Key Methods:
```php
// Check if workspace enforces fixed vendor
$isFixed = $ws->isFixedVendor();

// Get list of canonical package names in workspace
$names = $ws->packageNames();

// Find package by short name, canonical name, or alias (case-insensitive)
$pkg = $ws->findPackage('billing');

// Immutably add/update package definition (sorted alphabetically by directory/alias)
$newWs = $ws->withPackage($packageDefinition);

// Immutably remove package definition
[$newWs, $wasRemoved] = $ws->withoutPackage('billing');

// Convert to raw manifest structure for JSON storage
$manifestData = $ws->toManifestArray();

// Convert to normalized array representation (Arrayable)
$arrayData = $ws->toArray();
```

---

## 4. Package Model: `PackageDefinition`

`AlexKassel\WorkspaceManifest\DTOs\PackageDefinition` represents an individual package within a workspace. It implements `Illuminate\Contracts\Support\Arrayable`.

### ⚠️ The Canonical Name Invariant:
> **Core Architectural Rule:** `PackageDefinition::$name` **MUST ALWAYS** be a canonical Composer package name (`vendor/package`).
>
> A short name (e.g. `'billing'`) is **strictly forbidden** inside `PackageDefinition`. If instantiated with a name lacking a vendor slash `/`, the constructor throws an `InvalidArgumentException`:
> ```php
> // ❌ Throws InvalidArgumentException!
> new PackageDefinition(name: 'billing', workspace: 'packages');
>
> // ✅ Always use canonical name:
> new PackageDefinition(name: 'acme/billing', workspace: 'packages');
> ```

### Inflation & Deflation Lifecycle:

`PackageDefinition` acts as a pure domain object at runtime, while seamlessly collapsing for concise JSON storage on disk:

```
                  ┌──────────────────────────────────────────────┐
                  │          workspace.json (On Disk)            │
                  │  packages: ["billing", "vendor/pkg"]         │
                  └──────────────────────────────────────────────┘
                                  │               ▲
                         Inflation│               │Deflation
                     fromManifest()               │toManifestEntry()
                                  ▼               │
                  ┌──────────────────────────────────────────────┐
                  │       PackageDefinition (In-Memory DTO)      │
                  │  $name: ALWAYS canonical "acme/billing"       │
                  │  implements Arrayable                        │
                  └──────────────────────────────────────────────┘
```

1. **Inflation (`fromManifest`):**
   When parsing from `workspace.json`, if the parent workspace has a `vendor` configured (e.g. `"acme"`) and the package entry is a short slug (`"billing"` or `{"name": "billing"}`), `PackageDefinition::fromManifest()` automatically prepends the vendor prefix to form `"acme/billing"`.
2. **Deflation (`toManifestEntry`):**
   When serializing back to `workspace.json`, `toManifestEntry(?string $workspaceVendor)` checks whether the package vendor matches `$workspaceVendor`:
   - If it matches, the vendor prefix is stripped (e.g. `"acme/billing"` → `"billing"`).
   - If the package has no custom `alias`, `url`, or `skills`, it collapses to a scalar string (`"billing"`).
   - If it has custom metadata, it serializes as an object (`{"name": "billing", "alias": "..."}`).
   - Foreign packages (e.g. `"other-vendor/tool"`) retain their full name (`"other-vendor/tool"`).

### Helper Methods:
```php
$pkg = new PackageDefinition(
    name: 'acme/billing',
    workspace: 'packages',
    alias: 'BillingModule',
    url: 'git@github.com:acme/billing.git',
    skills: ['testing-best-practices'],
);

// 1. Canonical Name (always returns $this->name)
echo $pkg->canonicalName(); // "acme/billing"

// 2. Vendor extraction
echo $pkg->vendor(); // "acme"

// 3. Short slug extraction
echo $pkg->shortName(); // "billing"

// 4. Effective directory on disk (alias takes precedence; otherwise shortName if vendor matches)
echo $pkg->effectiveDirectory('acme'); // "BillingModule"

// 5. Arrayable serialization
$array = $pkg->toArray();
// Returns:
// [
//     'name' => 'acme/billing',
//     'alias' => 'BillingModule',
//     'url' => 'git@github.com:acme/billing.git',
//     'skills' => ['testing-best-practices'],
// ]

// 6. Immutable Fluent Withers
$updatedPkg = $pkg
    ->withAlias('NewAlias')
    ->withUrl('https://custom-git.com/acme/billing.git')
    ->withSkills(['package-verification', 'laravel-best-practices'])
    ->withWorkspace('modules');

// 7. CQS Mutation Symmetry: savePackage() & saveWorkspace()
// Persist DTOs directly without decomposing into primitive parameter lists:
$manifest = WorkspaceManifest::open();

// Save package directly
$manifest->savePackage($updatedPkg);

// Save workspace directly
$ws = new WorkspaceDefinition(name: 'modules', vendor: 'acme');
$manifest->saveWorkspace($ws);
```

---

## 5. Exceptions Reference

| Exception | Thrown When |
| :--- | :--- |
| `PackageNotFoundException` | Attempting to register an alias or update skills on a package that is not registered. |
| `PackageConflictException` | A package name or alias collides with an existing registration across any workspace (Rule F-03). |
| `WorkspaceNotFoundException` | Operating on a workspace that is not defined in `workspace.json`. |
| `WorkspaceAlreadyExistsException` | Attempting to register a workspace that is already defined. |
| `InvalidPackageNameException` | Package name violates Composer naming rules or lacks a vendor in multi-vendor workspace. |
| `InvalidPackageAliasException` | Alias contains directory traversal or slashes. |
| `InvalidVendorSlugException` | Vendor prefix contains invalid characters. |
| `InvalidWorkspacePathException` | Workspace path is absolute or attempts path traversal (`..`). |


