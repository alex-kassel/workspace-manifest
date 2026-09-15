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

- [x] **1.1. Fix Test Environment & PHPStan Baseline**
  - [x] Replace dynamic `class_alias` in `tests/TestCase.php` with direct `Tests\TestCase` inheritance.
  - [x] Ensure `vendor/bin/phpstan analyse -c packages/alex-kassel/workspace-manifest/phpstan.neon` passes with 0 errors on src and tests.
- [x] **1.2. Implement Strongly-Typed DTOs**
  - [x] Create `AlexKassel\WorkspaceManifest\DTOs\PackageDefinition` (properties: `name`, `alias`, `url`, `skills`, with helper methods: `canonicalName(?string $vendor)`, `effectiveDirectory()`, `isAliased()`, `hasCustomUrl()`).
  - [x] Create `AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition` (properties: `name`, `vendor`, `packages`, helper methods to query packages).
- [x] **1.3. Port Path Security & Normalization**
  - [x] Port `normalizeWorkspacePath(string $path): string` to prevent path traversal (`..`), absolute paths, and invalid segment characters.
  - [x] Apply path normalization in `registerWorkspace()`, `unregisterWorkspace()`, `addPackage()`, and `setDefaultWorkspace()`.
- [x] **1.4. Port Conflict Prevention (Rule F-03)**
  - [x] Enforce unified namespace conflict checks: alias cannot match existing package name; package name cannot match existing alias.
  - [x] Throw descriptive domain exception `PackageConflictException` on naming collisions.
- [x] **1.5. Port Missing Manifest Operations**
  - [x] Implement `updatePackageSkills(string $workspace, string $packageName, array $skills): self`.
  - [x] Implement `registerPackageAlias(string $workspace, string $packageName, string $alias): self`.
  - [x] Implement canonical name resolution for fixed-vendor flat workspaces (`vendor/pkg` vs `pkg`).
  - [x] Enforce deterministic ordering: `ksort` for workspaces, `usort` (alphabetical by effective name/alias) for package entries.
- [x] **1.6. Address Schema Reference Flexibility**
  - [x] Schema and JSON schema validation tested and aligned.
- [x] **1.7. Comprehensive Test Coverage**
  - [x] Add unit and feature tests covering path normalization, conflict prevention, DTO hydration, and alphabetical ordering.

---

## Phase 2: Manifest Engine Evaluation & Packagist Readiness

Goal: Evaluate ManifestEngine compatibility, verify boundary purity, update documentation, and prepare WorkspaceManifest for Packagist release.

- [x] **2.1. Nested Collection Validation Review**
  - [x] Verified `Manifest::mutate` and `ManifestValidationException` correctly enforce schema invariants across package definitions.
- [x] **2.2. DTO Serialization Parity**
  - [x] Verified `PackageDefinition` and `WorkspaceDefinition` map cleanly to and from array structures without requiring changes to ManifestEngine core.
  - [x] Implemented native `WorkspaceManifestDto` (implements `ManifestDto`), wiring `WorkspaceManifest::toDto()` and `saveDto()` directly to `ManifestEngine`, eliminating manual array-loop hydration and reducing code boilerplate.
- [x] **2.3. Documentation & Packagist Readiness**
  - [x] Updated `README.md` with complete API reference, examples, and badge links.
  - [x] Updated `composer.json` with metadata, homepage, script aliases, and validated via `composer validate --strict`.

---

## Phase 3: Toolkit Integration & Migration

Goal: Connect `alex-kassel/workspace-manifest` to `alex-kassel/workspace-development-toolkit` and eliminate duplicated code.

- [x] **3.1. Require Workspace Manifest in Toolkit**
  - [x] Added `alex-kassel/workspace-manifest` requirement to Toolkit's `composer.json`.
- [x] **3.2. Adapt Toolkit's `ManifestRepository`**
  - [x] Delegated all `workspace.json` package mutations (`registerPackageAlias`, `recordPackage`, `updatePackageSkills`, `forgetPackage`, `normalizeWorkspacePath`) to `WorkspaceManifest`.
  - [x] Retained `heal()` in Toolkit for reconstructing configuration from root `composer.json`.
  - [x] Resolved artisan command collision by namespacing the manifest setup command to `workspace-manifest:install`.
- [x] **3.3. Verify Toolkit Test Suite**
  - [x] 360 tests in `workspace-development-toolkit` suite executed: **354 passed, 0 failed, 6 skipped**.
  - [x] Chaos tests (`PackageCommandsChaosTest`, `WorkspaceCommandsChaosTest`, `StateChaosMachineTest`) pass with 100% success.
- [x] **3.4. Final Cleanup & Commit**
  - [x] Clean, atomic commits created across both packages.
