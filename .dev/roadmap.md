# 🗺️ WorkspaceManifest Roadmap

This document outlines planned architectural enhancements and RFC proposals for **WorkspaceManifest** (`alex-kassel/workspace-manifest`).

---

## 1. Dynamic Schema Resolution

### Problem
`WorkspaceSchema::DEFAULT_SCHEMA_PATH` references `./packages/alex-kassel/workspace-manifest/resources/schema.json`. When installed via Composer in an external project, this local mono-repo path does not exist, breaking IDE schema validation and autocomplete for consumers.

### Planned Solution
- Add runtime path detection in `WorkspaceSchema`: probe local path first, then `vendor/alex-kassel/workspace-manifest/resources/schema.json`, with a fallback to raw GitHub canonical URL.
- Update `WorkspaceManifest::init()` and `WorkspaceSchema::defaults()` to use the dynamically resolved schema URI.

---

## 2. Command-Query Separation (CQS) Symmetry for Mutations

### Problem
While read operations return rich typed DTOs (`PackageDefinition`, `WorkspaceDefinition`), write operations require decomposing objects into primitive scalars (`addPackage($workspace, $name, $alias, $url, $skills)`).

### Planned Solution
- Implement `savePackage(PackageDefinition $package): self` accepting a typed DTO directly.
- Implement `saveWorkspace(WorkspaceDefinition $workspace): self` for complete workspace state updates.
- Provide fluent immutable modifiers on `PackageDefinition` (`withAlias`, `withSkills`, `withUrl`).

---

## 3. Global Multi-Workspace Conflict Prevention (Rule F-03)

### Problem
Conflict checks currently validate collisions within the target workspace. In multi-workspace setups (e.g. `packages` and `modules`), a package or alias in `modules` can collide with one in `packages`, causing root directory collisions.

### Planned Solution
- Check package names and directory aliases globally across all registered workspaces.
- Throw `PackageConflictException` indicating which workspace holds the conflicting entry.

---

## 4. In-Memory O(1) Package Lookup Index

### Problem
`getPackage()`, `findPackageWorkspace()`, and `hasPackage()` loop through all workspaces on each invocation. In large mono-repos (50+ packages), repeated calls produce quadratic overhead.

### Planned Solution
- Build an associative in-memory index on initial load mapping package names, aliases, and canonical names to `PackageDefinition`.
- Invalidate and rebuild index automatically when atomic mutations take place.

---

## 5. Domain Event Dispatching via ManifestEngine

### Problem
Mutations happen silently without firing domain events, requiring consumers (`workspace-development-toolkit`) to manage manual cache invalidation.

### Planned Solution
- Dispatch domain events on mutation (`WorkspaceRegistered`, `WorkspaceUnregistered`, `PackageRecorded`, `PackageRemoved`).
- Enable external packages to reactively listen and invalidate their caches.
