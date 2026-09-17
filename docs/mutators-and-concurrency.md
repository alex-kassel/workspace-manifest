# Safe Mutators, Conflict Prevention & Reality Guards

This document details the mutation API, domain exception hierarchy, collision prevention rules, and physical filesystem integrity checks in `alex-kassel/workspace-manifest`.

---

## 1. Core Principle: Physical Reality vs. Manifest State

In a workspace monorepo, **the manifest must strictly reflect physical filesystem reality**.

Ambiguity or silent assumptions between manifest JSON and physical directories lead to catastrophic state corruption:
* If a workspace directory already exists with 5 packages inside, silently re-registering an empty workspace in JSON would delete the catalog of those 5 packages.
* If a workspace directory does not exist, adding a package to it creates an orphaned configuration without a physical root.

Therefore, `WorkspaceManifest` enforces **fail-fast domain exceptions** with zero silent overwrites.

---

## 2. Domain Exception Hierarchy

All domain exceptions implement `AlexKassel\WorkspaceManifest\Exceptions\WorkspaceManifestException`:

```text
WorkspaceManifestException (\Throwable)
  ├── WorkspaceAlreadyExistsException (InvalidArgumentException)
  ├── WorkspaceNotFoundException (InvalidArgumentException)
  ├── DefaultWorkspaceNotConfiguredException (RuntimeException)
  ├── InvalidPackageNameException (InvalidArgumentException)
  ├── InvalidVendorSlugException (InvalidArgumentException)
  ├── InvalidPackageAliasException (InvalidArgumentException)
  ├── InvalidRepositoryUrlTemplateException (InvalidArgumentException)
  ├── InvalidWorkspacePathException (InvalidArgumentException)
  └── PackageConflictException (RuntimeException)
```

### Exception Details:

* **`WorkspaceAlreadyExistsException`**:
  Thrown when `registerWorkspace($workspace)` is called for an already registered workspace.
* **`WorkspaceNotFoundException`**:
  Thrown when `addPackage()`, `unregisterWorkspace()`, `setWorkspaceVendor()`, or `setWorkspaceHook()` is invoked on an unregistered workspace.
* **`DefaultWorkspaceNotConfiguredException`**:
  Thrown by `getRequiredDefaultWorkspace()` if no default workspace is defined.
* **`InvalidPackageNameException`**:
  Carries `$packageName` and optional `$suggestion`.
* **`InvalidVendorSlugException`**:
  Carries `$vendor` and optional `$suggestion`.
* **`PackageConflictException`**:
  Carries `$conflictingName`, `$existingName`, and detailed rationale.

---

## 3. Conflict Prevention (Rule F-03)

Packages inside a workspace cannot have colliding effective directories or ambiguous aliases. `withPackage()` strictly enforces three collision rules:

1. **New package name conflicts with an existing alias:**
   If package A has alias `"billing"`, adding package B named `"acme/billing"` throws `PackageConflictException`.
2. **New package alias conflicts with an existing package name:**
   If package A is named `"acme/billing"`, adding package B with alias `"billing"` throws `PackageConflictException`.
3. **Duplicate aliases:**
   Two packages cannot share the same directory alias.

---

## 4. Mutator API Reference

All mutators execute atomically under the underlying `ManifestEngine` lock and automatically sort packages alphabetically by effective directory:

### Workspace Operations
```php
use AlexKassel\WorkspaceManifest\Facades\WorkspaceManifest;

// Register a new workspace (fails if already registered)
WorkspaceManifest::registerWorkspace(
    workspace: 'packages',
    vendor: 'alex-kassel',
    asDefault: true
);

// Unregister a workspace (fails if not found)
WorkspaceManifest::unregisterWorkspace('packages', reassignDefault: true);

// Set or update workspace vendor prefix
WorkspaceManifest::setWorkspaceVendor('packages', 'new-vendor');

// Set default workspace
WorkspaceManifest::setDefaultWorkspace('packages');

// Strict getter for default workspace
$default = WorkspaceManifest::getRequiredDefaultWorkspace();
```

### Package Operations
```php
// Add or update package
WorkspaceManifest::addPackage(
    workspace: 'packages',
    packageName: 'alex-kassel/billing',
    alias: 'Billing',
    url: 'git@github.com:alex-kassel/billing.git',
    skills: ['laravel-best-practices']
);

// Register / update package alias
WorkspaceManifest::registerPackageAlias('packages', 'billing', 'BillingMaster');

// Update agent skills assigned to package
WorkspaceManifest::updatePackageSkills('packages', 'billing', ['testing', 'laravel-best-practices']);

// Remove package (optionally prune workspace if it becomes empty)
$wasRemoved = WorkspaceManifest::removePackage('billing', workspace: 'packages', pruneEmptyWorkspace: false);
```
