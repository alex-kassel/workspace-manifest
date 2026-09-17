# Workspace Manifest Technical Audit & Refactoring Plan

**Package:** `alex-kassel/workspace-manifest`  
**Date:** 2026-09-17  
**Status:** Approved Technical Audit  

---

## Executive Summary

A comprehensive, read-only architectural audit of `alex-kassel/workspace-manifest` was conducted under Laravel 13 and PHP 8.3 constraints.

### Overall Package State
The package acts as the domain manifest repository and schema provider for `workspace.json` multi-package configurations. Its primary architecture (typed DTOs supporting fixed-vendor and multi-vendor workspaces, validated via `alex-kassel/manifest-engine`) is structurally sound. Test coverage is in place (27 tests, 196 assertions), and PHPStan passes at Level 8.

However, the codebase suffers from **boundary violations, silent data loss during specific mutation calls, dual-state cache desynchronization (split-brain), and undeclared/unnecessary dependencies**.

### Primary Sources of Complexity
1. **Single Responsibility Principle (SRP) Violation & Heavy Dependencies:** The package bundles a standalone procedural CLI runner (`stubs/workspace.stub`, 208 lines of `git clone`, `passthru`, ANSI formatting), an installation service (`WorkspaceRunnerInstaller`), and an artisan command (`workspace-manifest:install`), pulling in `alex-kassel/stub-engine` solely to replace two placeholders in one stub.
2. **Dual-State Desynchronization (Split-Brain):** `WorkspaceManifest` simultaneously maintains an in-memory DTO cache (`$cachedDto`) while performing non-atomic, direct mutations via `Manifest::set()`.
3. **Undeclared Transitive Dependency:** Direct imports of `League\Flysystem\WhitespacePathNormalizer` and `League\Flysystem\PathTraversalDetected` without `league/flysystem` listed in `composer.json`.
4. **100% Schema Duplication:** An 82-line hardcoded PHP array in `WorkspaceSchema::jsonSchema()` duplicates 93 lines of `resources/schema.json`.

### Reductions & Removals Summary
* **Remove dependency** `alex-kassel/stub-engine` from `composer.json`.
* **Extract/Remove runner infrastructure** (`WorkspaceRunnerInstaller`, `WorkspaceInstallCommand`, `stubs/workspace.stub`) to `workspace-development-toolkit`.
* **Delete dead code** methods `WorkspaceDefinition::toArray()` and `PackageDefinition::toArray()`.
* **Eliminate duplicate schema array** in `WorkspaceSchema::jsonSchema()` by delegating directly to `resources/schema.json`.

### Critical Risks
* **[P0] Silent Data Loss:** `setDefaultWorkspace()` and `setRepositoryUrlTemplate()` **never persist data to disk**. Changes are lost upon process termination or overwritten on the next call to `mutate()`.
* **[P1] Path Corruption:** `resolveRelativeFilename()` corrupts configured absolute paths located outside `base_path()`.
* **[P1] Undeclared Dependency:** Reliance on internal Flysystem normalizers risks runtime `ClassNotFoundException`.

### Issue Distribution
* Architecture & Package Boundaries: **40%**
* Implementation, Reliability & Bugs: **35%**
* Laravel-First & Dependencies: **15%**
* Code Style & Project Standards: **10%**

---

## Detailed Audit Findings

### [P0] Silent Data Loss on `setDefaultWorkspace()` and `setRepositoryUrlTemplate()`

* **Location:** [`src/WorkspaceManifest.php:153-161`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifest.php#L153-L161), [`src/WorkspaceManifest.php:173-179`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifest.php#L173-L179)
* **Problem:** These methods mutate the in-memory array of `Manifest` without triggering disk persistence and without invalidating `$this->cachedDto`.
* **Why this is a problem:**
  1. Executing `$wm->setDefaultWorkspace('foo')` or `$wm->setRepositoryUrlTemplate('...')` does not write changes to `workspace.json`. Any subsequent process or fresh instance loses these modifications.
  2. Any subsequent atomic mutation (e.g. `addPackage()`) calls `mutate()`, which re-reads the JSON file from disk, wiping out in-memory changes.
  3. Existing `$this->cachedDto` remains stale because neither method clears `$this->cachedDto = null;`.
* **Evidence:**
  In [`src/WorkspaceManifest.php:158`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifest.php#L158):
  ```php
  $this->manifest->set('default', $clean);
  ```
  In `alex-kassel/manifest-engine/src/Manifest.php:480-484`:
  ```php
  data_set($data, $key, $value);
  $this->data = $data;
  $this->isDirty = true;

  return $this; // Does not execute save()!
  ```
  Runtime verification confirms:
  ```php
  $wm = WorkspaceManifest::open($path)->init();
  $wm->setDefaultWorkspace('packages');
  $fresh = WorkspaceManifest::open($path);
  $fresh->getDefaultWorkspace(); // Evaluates to null! Disk file is unchanged.
  ```
* **Simplification:** Route all mutations through `$this->mutate()` and `WorkspaceManifestDto`:
  ```php
  public function setDefaultWorkspace(?string $workspace): self
  {
      $clean = $workspace !== null ? self::normalizeWorkspacePath($workspace) : null;

      return $this->mutate(function (array $data) use ($clean): array {
          $dto = WorkspaceManifestDto::fromArray($data);

          return (new WorkspaceManifestDto(
              schema: $dto->schema,
              default: $clean,
              repositoryUrlTemplate: $dto->repositoryUrlTemplate,
              workspaces: $dto->workspaces,
              extra: $dto->extra,
          ))->toArray();
      });
  }
  ```
* **Laravel/PHP Alternative:** Native atomic transaction via `Manifest::mutate()`.
* **Impact:** Guaranteed disk persistence and automatic DTO cache invalidation.
* **Pre-change Checks:** Verify against fresh instance reloads (`WorkspaceManifest::open($path)->getDefaultWorkspace()`).
* **Confidence:** High (empirically proven).

---

### [P1] Broken Path Resolution in `WorkspaceManifestServiceProvider::resolveRelativeFilename()`

* **Location:** [`src/WorkspaceManifestServiceProvider.php:35`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifestServiceProvider.php#L35), [`src/WorkspaceManifestServiceProvider.php:73-87`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifestServiceProvider.php#L73-L87)
* **Problem:** When `workspace-manifest.path` is configured with an absolute path outside `base_path()` (e.g. `/var/shared/workspace.json`), `resolveRelativeFilename()` returns that absolute path. The service provider then executes:
  ```php
  WorkspaceManifest::open(base_path($this->resolveRelativeFilename()));
  ```
* **Why this is a problem:**
  In Laravel, `base_path($path)` concatenates `$this->basePath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR)`. This creates a corrupted path: `/Users/.../local-1/var/shared/workspace.json`.
* **Evidence:**
  Lines 84-86:
  ```php
  return str_starts_with($cleanPath, $cleanBase)
      ? ltrim(substr($cleanPath, strlen($cleanBase)), '/')
      : $cleanPath;
  ```
  Line 35:
  ```php
  $this->app->singleton(WorkspaceManifest::class, function () {
      return WorkspaceManifest::open(base_path($this->resolveRelativeFilename()));
  });
  ```
* **Simplification:** Resolve absolute path handling properly:
  ```php
  protected function resolveManifestPath(): string
  {
      $configured = config(self::CONFIG_PATH_KEY);
      $path = is_string($configured) && trim($configured) !== ''
          ? trim($configured)
          : WorkspaceManifest::DEFAULT_FILENAME;

      return $this->isAbsolutePath($path) ? $path : base_path($path);
  }
  ```
* **Laravel/PHP Alternative:** Standard path inspection (`str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)`).
* **Impact:** Robust handling of relative and absolute manifest locations.
* **Pre-change Checks:** Ensure `test_service_provider_registers_custom_relative_path` passes.
* **Confidence:** High.

---

### [P1] Undeclared Dependency on `league/flysystem` (`WhitespacePathNormalizer`)

* **Location:** [`composer.json:22-28`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/composer.json#L22-L28), [`src/WorkspaceManifest.php:15-16, 60-65`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifest.php#L60-L65), [`src/WorkspaceManifestServiceProvider.php:13, 80-83`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifestServiceProvider.php#L80-L83)
* **Problem:** Direct imports of `League\Flysystem\WhitespacePathNormalizer` and `League\Flysystem\PathTraversalDetected` without `league/flysystem` in `composer.json`.
* **Why this is a problem:**
  1. Package relies on transitive vendor presence through `illuminate/filesystem`.
  2. `WhitespacePathNormalizer` is an internal Flysystem class designed for filesystem adapters, not general domain path validation.
  3. Instantiating `new WhitespacePathNormalizer` on every path validation adds unnecessary object allocation overhead.
* **Evidence:**
  `composer.json` contains no `league/flysystem` entry.
* **Simplification:** Replace Flysystem normalizer with a standard PHP segment-based relative path normalizer:
  ```php
  public static function normalizeWorkspacePath(string $path): string
  {
      $trimmed = trim(str_replace('\\', '/', $path));

      if ($trimmed === '' || str_starts_with($trimmed, '/') || preg_match('/^[a-zA-Z]:\//', $trimmed)) {
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
          $parts[] = $segment;
      }

      if (empty($parts)) {
          throw new InvalidWorkspacePathException($path, 'Workspace path cannot be empty or root directory.');
      }

      return implode('/', $parts);
  }
  ```
* **Laravel/PHP Alternative:** Pure PHP deterministic path resolution.
* **Impact:** Removes undeclared external dependency, eliminates instantiation overhead, maintains identical validation contracts.
* **Pre-change Checks:** `WorkspaceManifestTest::test_path_normalization_and_validation`.
* **Confidence:** High.

---

### [P1] SRP Violation & Bloat: `WorkspaceRunnerInstaller`, `stubs/workspace.stub`, and `alex-kassel/stub-engine`

* **Location:**
  - [`composer.json:25`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/composer.json#L25)
  - [`src/Services/WorkspaceRunnerInstaller.php:1-105`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/Services/WorkspaceRunnerInstaller.php#L1-L105)
  - [`src/Console/Commands/WorkspaceInstallCommand.php:1-67`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/Console/Commands/WorkspaceInstallCommand.php#L1-L67)
  - [`stubs/workspace.stub:1-208`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/stubs/workspace.stub#L1-L208)
  - [`src/WorkspaceManifestServiceProvider.php:38-42, 52-54, 66-68`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifestServiceProvider.php#L38-L42)
* **Problem:** As a domain manifest and schema package, `workspace-manifest` contains a 208-line procedural Git-cloning runner, an installer service, and a command, requiring `alex-kassel/stub-engine` for basic string replacement.
* **Why this is a problem:**
  1. Package boundary leak: Git operations, package restoration, and CLI execution belong in `workspace-development-toolkit`.
  2. `alex-kassel/stub-engine` is imported solely for `scaffoldFile()` on two tokens (`{{ manifestPath }}`, `{{ runnerName }}`).
  3. Unused internally by other monorepo tools.
* **Simplification:**
  - Transfer `stubs/workspace.stub`, `WorkspaceRunnerInstaller`, and `WorkspaceInstallCommand` to `workspace-development-toolkit`.
  - Remove `"alex-kassel/stub-engine"` from `composer.json`.
  - If stub publishing must remain, use Laravel native `$this->publishes()` or `File::put()` with `str_replace()`.
* **Laravel/PHP Alternative:** `$this->publishes()` or native `Illuminate\Support\Facades\File`.
* **Impact:** Eliminates 380 lines of out-of-scope code and 1 external package dependency.
* **Pre-change Checks:** Transfer existing `WorkspaceRunnerTest` test cases to `workspace-development-toolkit`.
* **Confidence:** High.

---

### [P2] 100% Schema Duplication: `WorkspaceSchema::jsonSchema()` vs `resources/schema.json`

* **Location:** [`src/Schemas/WorkspaceSchema.php:56-138`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/Schemas/WorkspaceSchema.php#L56-L138) and [`resources/schema.json:1-93`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/resources/schema.json#L1-L93)
* **Problem:** 82 lines of PHP array code replicate `resources/schema.json` verbatim.
* **Why this is a problem:** Violates Single Source of Truth (SSOT). Schema modifications require manual updates in two separate files, risking schema drift.
* **Simplification:**
  ```php
  public function jsonSchema(): array
  {
      /** @var array<string, mixed> */
      return File::json(__DIR__.'/../../resources/schema.json');
  }
  ```
* **Laravel/PHP Alternative:** `File::json()` (`Illuminate\Support\Facades\File`).
* **Impact:** Removes 80 lines of duplicate code; guarantees SSOT.
* **Pre-change Checks:** `WorkspaceManifestTest::test_json_schema_export_and_specification`.
* **Confidence:** High.

---

### [P2] Cache Desynchronization and Dual State Architecture

* **Location:** [`src/WorkspaceManifest.php:26, 115, 147, 189, 209, 223, 265, 294`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifest.php#L26)
* **Problem:** Two parallel data reading methods exist:
  1. Via DTO: `toDto()`, `getPackage()`, `getPackageNames()`, `getWorkspaceDefinitions()`.
  2. Directly via raw dot-notation in `Manifest`: `getDefaultWorkspace()`, `getWorkspaces()`, `hasWorkspace()`, `getWorkspaceVendor()`.
* **Why this is a problem:**
  - `hasWorkspace('foo')` evaluates `$this->manifest->has("workspaces.{$clean}")`. If a workspace name contains a dot (e.g. `packages/v1.0`), Laravel's `Arr::has` interprets the dot as array nesting and returns `false`.
  - External updates to the file are not detected if `$cachedDto` is retained.
* **Simplification:** Unify all read operations around the DTO model or direct `$this->manifest->all()` arrays. Ensure `hasWorkspace()` accesses array keys directly without dot-notation splitting.
* **Confidence:** High.

---

### [P2] Dead Code: Unused `toArray()` in `WorkspaceDefinition` and `PackageDefinition`

* **Location:** [`src/DTOs/WorkspaceDefinition.php:268-276`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/DTOs/WorkspaceDefinition.php#L268-L276), [`src/DTOs/PackageDefinition.php:103-112`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/DTOs/PackageDefinition.php#L103-L112)
* **Problem:** Methods `WorkspaceDefinition::toArray()` and `PackageDefinition::toArray()` produce verbose DTO dumps. Manifest serialization actually uses `toManifestArray()` and `toManifestEntry()`.
* **Why this is a problem:** The methods are never called in production code, creating confusion regarding the serialization format.
* **Simplification:** Delete `WorkspaceDefinition::toArray()` and `PackageDefinition::toArray()`, or unify serialization under standard `toArray()`.
* **Confidence:** High.

---

### [P2] Redundant Disk I/O and Suboptimal Mutation in `removePackage()`

* **Location:** [`src/WorkspaceManifest.php:414-440`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifest.php#L414-L440)
* **Problem:**
  1. Target workspace resolution occurs outside the transaction lock.
  2. If the package is not found (`$wasRemoved === false`), `mutate()` still re-saves `$newDto->toArray()`, triggering disk writes, validation, and saving events unnecessarily.
* **Simplification:** Resolve the workspace within the lock callback, and return unmodified `$data` when no removal takes place:
  ```php
  $this->mutate(function (array $data) use ($workspace, $cleanPackageName, $pruneEmptyWorkspace, &$removed): array {
      $dto = WorkspaceManifestDto::fromArray($data);
      $targetWs = $workspace !== null ? self::normalizeWorkspacePath($workspace) : $dto->findPackageWorkspace($cleanPackageName);

      if ($targetWs === null) {
          return $data;
      }

      [$newDto, $wasRemoved] = $dto->withoutPackage($cleanPackageName, $targetWs, $pruneEmptyWorkspace);
      $removed = $wasRemoved;

      return $wasRemoved ? $newDto->toArray() : $data;
  });
  ```
* **Confidence:** High.

---

### [P2] Silent Loss of Custom Package Metadata in DTO Serialization

* **Location:** [`src/DTOs/PackageDefinition.php:41-55, 119-140`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/DTOs/PackageDefinition.php#L41-L55)
* **Problem:** The JSON Schema allows `'additionalProperties' => true` on package definitions. However, `PackageDefinition::fromManifest()` only parses `name`, `alias`, `url`, `skills`. All other properties are stripped when `toManifestEntry()` is invoked.
* **Why this is a problem:** Discrepancy with `WorkspaceManifestDto` (which preserves root-level `$extra`) causing silent metadata loss for custom tool integrations.
* **Simplification:** Add `public readonly array $extra = []` to `PackageDefinition` and preserve it during `toManifestEntry()`.
* **Confidence:** High.

---

### [P3] Localized Conflict Prevention Limited to Single Workspace (Rule F-03 Gap)

* **Location:** [`src/DTOs/WorkspaceDefinition.php:135-173`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/DTOs/WorkspaceDefinition.php#L135-L173)
* **Problem:** Rule F-03 conflict prevention runs strictly within `WorkspaceDefinition::withPackage()`.
* **Why this is a problem:** When multiple workspaces are registered (e.g. `packages` and `modules`), identical aliases across different workspaces go undetected, creating directory collisions at the project root level.
* **Simplification:** Enforce alias and name collision checks across all workspaces in `WorkspaceManifestDto::withPackage()`.
* **Confidence:** High.

---

### [P3] Strict Fallback Encapsulation Violation (Raw Literals in Method Bodies)

* **Location:** [`src/WorkspaceManifest.php:189, 294`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifest.php#L189)
* **Problem:** Methods use raw literals `[]` as fallbacks in `manifest->get('workspaces', [])`.
* **Why this is a problem:** Project rules strictly prohibit raw literals in class bodies.
* **Simplification:** Define typed class constant `public const DEFAULT_EMPTY_ARRAY = [];`.
* **Confidence:** High.

---

### [P3] Inconsistent Semantics in `registerPackageAlias` and `updatePackageSkills`

* **Location:** [`src/WorkspaceManifest.php:388-409`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/WorkspaceManifest.php#L388-L409)
* **Problem:** When modifying an alias or skills for a non-existent package, `getPackage()` returns null, leading to silent creation of a new incomplete package instead of raising an exception.
* **Confidence:** Medium.

---

### [Info] Incomplete Validation of `skills` Array Items in `ValidPackageEntryRule`

* **Location:** [`src/Rules/ValidPackageEntryRule.php:43-45`](file:///Users/alex/Projects/PHP/Herd/local-1/packages/alex-kassel/workspace-manifest/src/Rules/ValidPackageEntryRule.php#L43-L45)
* **Observation:** The validation rule verifies `is_array($value['skills'])` but does not check if the array elements are non-empty strings, allowing non-string items through validation (filtered later in DTO).
* **Confidence:** High.

---

## Conceptual Redesign ("From Scratch")

If redesigned cleanly from first principles:
1. **Scope:** Dedicated purely to reading, validating, and mutating `workspace.json`. Zero runner scripts, zero Git process orchestration.
2. **Architecture:** `WorkspaceManifest` is a thin repository delegating directly to `WorkspaceManifestDto`. All mutations pass through an atomic `mutate()` pipeline, guaranteeing disk persistence and cache coherence.
3. **SSOT:** `resources/schema.json` is the sole schema definition, loaded directly by `WorkspaceSchema::jsonSchema()`.
4. **Dependencies:** Minimal surface: `php`, `illuminate/support`, `illuminate/filesystem`, and `alex-kassel/manifest-engine`. No Flysystem normalizer leaks, no `stub-engine`.

---

## Removals Inventory

1. **Dependency:** `"alex-kassel/stub-engine": "^0.0.2"` in `composer.json`.
2. **Service:** `src/Services/WorkspaceRunnerInstaller.php` (105 lines).
3. **Command:** `src/Console/Commands/WorkspaceInstallCommand.php` (67 lines).
4. **Stub:** `stubs/workspace.stub` (208 lines).
5. **Provider Registrations:** Provider lines 38-42, 52-54, 66-68 in `WorkspaceManifestServiceProvider.php`.
6. **Tests:** `tests/WorkspaceRunnerTest.php` (146 lines).
7. **Dead Code:** `WorkspaceDefinition::toArray()` and `PackageDefinition::toArray()`.
8. **Duplicate Schema Code:** 82 lines of PHP array in `WorkspaceSchema::jsonSchema()`.

---

## Simplifications Inventory

1. **`WorkspaceSchema::jsonSchema()`:** Delegate to `File::json(__DIR__.'/../../resources/schema.json')`.
2. **`normalizeWorkspacePath()`:** Replace `WhitespacePathNormalizer` with standard PHP segment-based path normalizer.
3. **`WorkspaceManifestServiceProvider` Path Resolution:** Replace Flysystem checks with explicit absolute/relative detection.
4. **`setDefaultWorkspace()` and `setRepositoryUrlTemplate()`:** Convert to atomic `mutate()` transactions.

---

## Laravel / PHP / Package Replacements

| Current Implementation | Proposed Replacement | Benefit | Migration Risk |
| :--- | :--- | :--- | :--- |
| `AlexKassel\StubEngine\Services\StubEngine` in `WorkspaceRunnerInstaller` | Transfer runner to `workspace-development-toolkit` or use native `File::put` + `str_replace` | Removes heavy unused dependency, restores SRP | Minimal |
| `League\Flysystem\WhitespacePathNormalizer` in `WorkspaceManifest` | Deterministic PHP segment loop | Removes undeclared Flysystem dependency | Zero |
| 82-line array in `WorkspaceSchema::jsonSchema()` | `File::json(__DIR__.'/../../resources/schema.json')` | Eliminates schema duplication (SSOT) | Zero |
| `this->manifest->set()` in `setDefaultWorkspace` / `setRepositoryUrlTemplate` | `$this->mutate()` via `WorkspaceManifestDto` | Fixes critical P0 silent data loss bug | Zero |
| Raw literal `[]` fallbacks in `WorkspaceManifest` | Class constant `public const DEFAULT_EMPTY_ARRAY = [];` | Complies with project standards | Zero |

---

## Critical Risks (P0 / P1)

* **[P0] Silent Data Loss:** `setDefaultWorkspace()` and `setRepositoryUrlTemplate()` fail to persist data to disk.
* **[P1] Path Corruption:** Service provider path resolution corrupts external absolute paths.
* **[P1] Undeclared Flysystem Dependency:** Potential runtime failure due to missing package in `composer.json`.
* **[P1] Architectural Contamination:** Git-cloning runner and stub engine inside schema manifest package.

---

## Components Kept As-Is

1. **`AlexKassel\ManifestEngine` Integration:** Core lock handling, JSON encoding, and schema validation work reliably.
2. **DTO Hierarchy (`WorkspaceManifestDto`, `WorkspaceDefinition`, `PackageDefinition`):** Encapsulates fixed-vendor and multi-vendor domain rules cleanly with strong typing.
3. **`ValidPackageEntryRule`:** Necessary for polymorphic package entries (string vs descriptor object).
4. **Custom Exceptions:** `InvalidWorkspacePathException` and `PackageConflictException` are concise and expressive.

---

## Actionable Phased Refactoring Plan

### Phase 1: Critical Reliability & Correctness Fixes (P0 / P1)

- [x] **Step 1.1: Fix persistence in `setDefaultWorkspace()` and `setRepositoryUrlTemplate()` [P0]**
  - **Problem:** Methods only modify in-memory state of `Manifest`; disk writes are never executed.
  - **Solution:** Wrap mutations inside `$this->mutate()`, reconstructing `WorkspaceManifestDto` and saving via atomic lock. Add regression tests validating persistence across distinct `WorkspaceManifest::open()` instances.

- [x] **Step 1.2: Fix path resolution in `WorkspaceManifestServiceProvider` [P1]**
  - **Problem:** `resolveRelativeFilename()` corrupts absolute paths outside `base_path()` by passing them to `base_path()`.
  - **Solution:** Differentiate absolute paths from relative ones (`$this->isAbsolutePath($path) ? $path : base_path($path)`). Ensure relative filename registered in `ManifestRegistry` is formatted correctly without breaking external paths.
  - **Outcome:** Resolved universally inside `ManifestEngine` (`resolvePath()`, `fullPath()`); eliminated redundant `resolveRelativeFilename()` and all Flysystem imports from `WorkspaceManifestServiceProvider`.

- [x] **Step 1.3: Eliminate undeclared `league/flysystem` dependency [P1]**
  - **Problem:** Direct usage of `WhitespacePathNormalizer` and `PathTraversalDetected` without package declaration in `composer.json`.
  - **Solution:** Replace Flysystem normalizer in `WorkspaceManifest::normalizeWorkspacePath()` with a pure PHP segment loop validating against path traversal (`..`) and empty segments.
  - **Outcome:** Completely replaced with pure PHP segment normalization; zero undeclared dependencies remain.

---

### Phase 2: Deduplication, Schema Synchronization & Data Integrity (P2)

- [x] **Step 2.1: Dynamic Schema Generation via `ManifestEngine` `JsonSchemaCompiler` [P2]**
  - **Problem:** 82-line raw array in `WorkspaceSchema::jsonSchema()` duplicated `resources/schema.json` without automated generation.
  - **Solution:** Implemented `JsonSchemaCompiler` in `ManifestEngine` powered by native `ValidationRuleParser` and `HasJsonSchema` contract on `ValidPackageEntryRule`. `WorkspaceSchema` now declares only `rules()`, `descriptions()`, and `types()`, inheriting dynamic on-the-fly Draft-07 generation from `BaseSchema::jsonSchema()`.

- [x] **Step 2.2: Strict Schema Enforcement (Dropped YAGNI `$extra`) [P2]**
  - **Decision:** Explicitly dropped arbitrary `$extra` property bag. `workspace.json` is strictly machine-generated and machine-maintained by the package and CLI commands; arbitrary user-injected properties are neither supported nor required. Package schema remains strictly focused on `name`, `alias`, `url`, and `skills`.

- [x] **Step 2.3: Optimize mutation and eliminate redundant I/O in `removePackage()` [P2]**
  - **Problem:** Target workspace resolved outside lock; non-existent packages trigger full file re-saves.
  - **Solution:** Move workspace resolution inside `mutate()`; return original `$data` array unmodified when `$wasRemoved === false`.
  - **Outcome:** Target workspace is resolved within the lock callback; non-removals return `$data` immediately without saving or firing events.

- [x] **Step 2.4: Eliminate dual-state dot-notation risks in `WorkspaceManifest` [P2]**
  - **Problem:** `hasWorkspace()` and `getWorkspaceVendor()` use dot-notation which fails if workspace paths contain periods.
  - **Solution:** Direct lookup on workspace key via `$this->toDto()->hasWorkspace($clean)` or associative array key check.
  - **Outcome:** Unified reading methods through `$this->toDto()`; eliminated all dot-notation parsing hazards on dotted workspace paths.

---

### Phase 3: Package Boundaries, Dependency Cleanup & Standards Compliance (P1 / P2 / P3)

- [ ] **Step 3.1: Extract runner infrastructure to `workspace-development-toolkit` [P1]**
  - **Problem:** `stubs/workspace.stub`, `WorkspaceRunnerInstaller`, and `WorkspaceInstallCommand` violate SRP and drag in `alex-kassel/stub-engine`.
  - **Solution:** Move runner files and commands to `workspace-development-toolkit`. Remove `"alex-kassel/stub-engine"` from `composer.json`. Remove registrations from `WorkspaceManifestServiceProvider`.

- [ ] **Step 3.2: Delete dead code in DTOs [P2]**
  - **Problem:** `WorkspaceDefinition::toArray()` and `PackageDefinition::toArray()` are unused in production.
  - **Solution:** Remove both methods and their corresponding redundant assertions.

- [ ] **Step 3.3: Comply with Strict Fallback Encapsulation [P3]**
  - **Problem:** Raw literals `[]` passed to `manifest->get()` in `WorkspaceManifest`.
  - **Solution:** Declare `public const DEFAULT_EMPTY_ARRAY = [];` and replace literal occurrences.

- [ ] **Step 3.4: Expand conflict check rule F-03 across all workspaces [P3]**
  - **Problem:** `WorkspaceDefinition::withPackage()` only checks within the local workspace.
  - **Solution:** Add cross-workspace alias and package name collision checks in `WorkspaceManifestDto::withPackage()`.

---

### Phase 4: Verification & Static Analysis

- [ ] **Step 4.1: Run test suite**
  - **Action:** Execute `vendor/bin/phpunit packages/alex-kassel/workspace-manifest/tests` ensuring all 27+ tests pass.
- [ ] **Step 4.2: Run static analysis**
  - **Action:** Execute `vendor/bin/phpstan analyse -c packages/alex-kassel/workspace-manifest/phpstan.neon` at Level 8.
- [ ] **Step 4.3: Run code style formatter**
  - **Action:** Execute `vendor/bin/pint packages/alex-kassel/workspace-manifest --format agent`.
