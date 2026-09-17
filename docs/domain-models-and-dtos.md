# Workspace & Package Domain Models Guide

This document describes the typed domain models and Data Transfer Objects (DTOs) that represent multi-package workspace configurations in `alex-kassel/workspace-manifest`.

---

## 1. Overview & Hierarchy

A `workspace.json` file is represented in PHP as an object graph of immutable, strongly-typed DTOs:

```text
WorkspaceManifestDto
  ├── $schema (string|null)
  ├── default (string|null)
  ├── repositoryUrlTemplate (string)
  ├── extra (array)
  └── workspaces (array<string, WorkspaceDefinition>)
        ├── name (string)
        ├── vendor (string|null)
        ├── hooks (array|null)
        └── packages (array<int, PackageDefinition>)
              ├── name (string)
              ├── workspace (string)
              ├── alias (string|null)
              ├── url (string|null)
              └── skills (array<string>)
```

---

## 2. Root Model: `WorkspaceManifestDto`

`AlexKassel\WorkspaceManifest\DTOs\WorkspaceManifestDto` represents the root manifest document.

### Properties:
* `schema`: Path or URL to the JSON Schema.
* `default`: The default workspace directory (e.g. `"packages"`).
* `repositoryUrlTemplate`: Remote Git repository URL template (e.g. `"git@github.com:{package}.git"`).
* `workspaces`: Associative map of `[workspaceName => WorkspaceDefinition]`.
* `extra`: Unmapped custom properties preserved for forward compatibility.

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

// Get all package names across all workspaces
$allNames = $dto->packageNames();
```

---

## 3. Workspace Model: `WorkspaceDefinition`

`AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition` represents an individual workspace directory on disk.

### Fixed-Vendor vs. Multi-Vendor Workspaces:
* **Fixed-Vendor Workspaces (`vendor !== null`):**
  When a workspace specifies a vendor (e.g. `"vendor": "acme"`), packages belonging to that vendor are stored as simple short names (e.g. `"billing"` instead of `"acme/billing"`). This yields cleaner JSON and matches flat directory layouts:
  ```json
  "packages": {
    "vendor": "acme",
    "packages": ["billing", "auth"]
  }
  ```
* **Multi-Vendor / Vendor-less Workspaces (`vendor === null`):**
  Packages must explicitly specify their canonical vendor prefix:
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

// Get list of package names
$names = $ws->packageNames();

// Find package by local name, canonical name, or alias (case-insensitive)
$pkg = $ws->findPackage('billing');

// Immutably add/update package definition
$newWs = $ws->withPackage($packageDefinition);

// Immutably remove package definition
[$newWs, $wasRemoved] = $ws->withoutPackage('billing');
```

---

## 4. Package Model: `PackageDefinition`

`AlexKassel\WorkspaceManifest\DTOs\PackageDefinition` represents an individual package within a workspace.

### Properties:
* `name`: Stored package name (e.g. `"billing"` in fixed-vendor workspace, or `"acme/billing"` in vendor-less workspace).
* `workspace`: Relative directory of the parent workspace (e.g. `"packages"`).
* `alias`: Custom directory alias (e.g. `"Billing"`), or `null`.
* `url`: Custom Git remote clone URL, or `null`.
* `skills`: Array of agent skills assigned to this package (e.g. `["laravel-best-practices"]`).

### Name Resolution & Effective Directory:

```php
// Stored name: what is physically written in workspace.json
echo $pkg->name; // "billing"

// Canonical name: resolves full vendor/package name
echo $pkg->canonicalName($ws->vendor); // "acme/billing"

// Effective directory: alias takes precedence if defined; otherwise stored name
echo $pkg->effectiveDirectory(); // "Billing" or "billing"
```

### Manifest Serialization:
A `PackageDefinition` serializes to a clean scalar string if it only has a name without extra attributes, or to an object if it defines an alias, url, or skills:

```php
// Pure string format in JSON: "billing"
$entry = $pkg->toManifestEntry();

// Object format in JSON:
// {
//   "name": "billing",
//   "alias": "BillingModule",
//   "skills": ["testing"]
// }
```
