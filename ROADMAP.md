# Workspace Manifest Migration Roadmap

This document outlines the phased migration plan for transferring domain manifest logic from `alex-kassel/workspace-development-toolkit` into `alex-kassel/workspace-manifest`, built on top of `alex-kassel/manifest-engine`.

---

## Architectural Boundaries

1. **`ManifestEngine` (Universal Manifest Layer)**:
   - File locking (`flock` shared & exclusive) and atomic file persistence.
   - Validation integration via Laravel `ValidatorFactory`.
   - Dynamic JSON Schema (Draft-07) export.
   - Zero awareness of Composer, Git, or domain-specific workspace concepts.

2. **`WorkspaceManifest` (Domain Helper / Manifest Layer)**:
   - Specialized manager for `workspace.json`.
   - Domain invariants: workspace paths, vendor prefixes, aliases, skills, conflict detection.
   - Strict DTO representation for packages and workspaces.
   - Standalone zero-dependency runner (`bin/workspace`).
   - Zero filesystem mutations on external files (does not touch `composer.json`, git repos, or symlinks).

3. **`WorkspaceDevelopmentToolkit` (Developer Orchestration Layer)**:
   - Developer tooling, CLI orchestration, and filesystem scaffolding.
   - Git repository operations (cloning, branch management, gitignore).
   - Composer integration (`repositories.path` syncing, package installation).
   - Consumes `WorkspaceManifest` for all `workspace.json` reading and state mutations.

---

## Phase 1: Workspace Manifest Domain Enhancement

Goal: Enhance `alex-kassel/workspace-manifest` to satisfy all requirements of the Toolkit while keeping code clean, typed, and robust.

- [ ] **1.1. Fix Test Environment & PHPStan Baseline**
  - [ ] Replace dynamic `class_alias` in `tests/TestCase.php` with direct `Orchestra\Testbench\TestCase` inheritance.
  - [ ] Ensure `vendor/bin/phpstan analyse packages/alex-kassel/workspace-manifest` passes with 0 errors.
- [ ] **1.2. Implement Strongly-Typed DTOs**
  - [ ] Create `AlexKassel\WorkspaceManifest\DTOs\PackageDefinition` (properties: `name`, `alias`, `url`, `skills`, with helper methods: `canonicalName(?string $vendor)`, `effectiveDirectory()`, `isAliased()`, `hasCustomUrl()`).
  - [ ] Create `AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition` (properties: `path`, `vendor`, `packages`, helper methods to query packages).
- [ ] **1.3. Port Path Security & Normalization**
  - [ ] Port `normalizeWorkspacePath(string $path): string` to prevent path traversal (`..`), absolute paths, and invalid segment characters.
  - [ ] Apply path normalization in `registerWorkspace()`, `unregisterWorkspace()`, `addPackage()`, and schema validation.
- [ ] **1.4. Port Conflict Prevention (Rule F-03)**
  - [ ] Enforce unified namespace conflict checks: alias cannot match existing package name; package name cannot match existing alias.
  - [ ] Throw descriptive domain exceptions on naming collisions.
- [ ] **1.5. Port Missing Manifest Operations**
  - [ ] Implement `updatePackageSkills(string $workspace, string $packageName, array $skills): self`.
  - [ ] Implement `registerPackageAlias(string $workspace, string $packageName, string $alias): self`.
  - [ ] Implement canonical name resolution for fixed-vendor flat workspaces (`vendor/pkg` vs `pkg`).
  - [ ] Enforce deterministic ordering: `ksort` for workspaces, `usort` (alphabetical by effective name/alias) for package entries.
- [ ] **1.6. Address Schema Reference Flexibility**
  - [ ] Ensure `$schema` path handles both local monorepo development and composer vendor installations gracefully.
- [ ] **1.7. Comprehensive Test Coverage**
  - [ ] Add unit and feature tests covering path normalization, conflict prevention, DTO hydration, and alphabetical ordering.

---

## Phase 2: Manifest Engine Evaluation

Goal: Evaluate if any low-level features developed in Phase 1 warrant abstraction into `alex-kassel/manifest-engine`.

- [ ] **2.1. Nested Collection Validation Review**
  - [ ] Ensure `Manifest::mutate` provides clean error reporting when validation rules on wildcard paths fail.
- [ ] **2.2. DTO Serialization Parity**
  - [ ] Verify `toDto()` / `saveDto()` integration works seamlessly with nested domain collections if needed.

---

## Phase 3: Toolkit Integration & Migration

Goal: Connect `alex-kassel/workspace-manifest` to `alex-kassel/workspace-development-toolkit` and eliminate duplicated code.

- [ ] **3.1. Require Workspace Manifest in Toolkit**
  - [ ] Add `alex-kassel/workspace-manifest` to Toolkit's `composer.json`.
- [ ] **3.2. Adapt Toolkit's `ManifestRepository`**
  - [ ] Delegate `ManifestRepository` calls to `WorkspaceManifest` instance.
  - [ ] Retain healing logic (`heal()` from root `composer.json`) in Toolkit as a dedicated recovery action or helper.
- [ ] **3.3. Verify Toolkit Test Suite**
  - [ ] Run full test suite: `vendor/bin/phpunit packages/alex-kassel/workspace-development-toolkit/tests`.
  - [ ] Run Chaos tests (`PackageCommandsChaosTest`, `WorkspaceCommandsChaosTest`, `StateChaosMachineTest`).
- [ ] **3.4. Final Cleanup**
  - [ ] Remove legacy duplicate code and unused methods in Toolkit once all tests pass.
