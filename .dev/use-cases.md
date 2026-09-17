# 💡 Real-World Use Cases for WorkspaceManifest

`alex-kassel/workspace-manifest` provides the typed domain model, JSON Schema validation, and concurrency-safe repository for `workspace.json` multi-package monorepo environments. Below are five practical, high-value use cases demonstrating why and how `WorkspaceManifest` is utilized.

---

## Use Case 1: Monorepo Package Registry & Directory Mapping

### Context
In a modular PHP/Laravel monorepo or private package ecosystem, code is distributed across local package directories (e.g. `packages/*`, `components/*`). Developers and build tooling need a single, Git-tracked source of truth specifying:
* Registered local packages and their respective workspace directories.
* Package repository URLs for cloning or remote synchronization.
* Which workspace acts as the primary/default root for new package scaffolding.

### Why WorkspaceManifest Solves It
* **Deterministic Resolution:** Translates short package names into absolute disk paths and Composer path repositories.
* **Schema Enforcement:** Validates `workspace.json` against strict Draft-07 JSON Schema backed by `alex-kassel/manifest-engine`.
* **Zero Database Requirements:** State lives entirely in `workspace.json`, branching and versioning seamlessly alongside application source code.

```php
use AlexKassel\WorkspaceManifest\WorkspaceManifest;

$manifest = WorkspaceManifest::open(base_path('workspace.json'));

// Scaffold a new package into the default workspace
$manifest->addPackage('domain-billing', alias: 'billing');

// Query absolute package location
$package = $manifest->getPackage('alex-kassel/domain-billing');
$path = $package->fullPath(base_path());
```

---

## Use Case 2: Fixed-Vendor vs Multi-Vendor Workspace Modeling

### Context
Monorepos often combine internally authored packages (all sharing a company vendor prefix) with external third-party forks or client modules:
* `packages/`: Internal company packages (`alex-kassel/*`). Writing `alex-kassel/` repeatedly in `workspace.json` is redundant and error-prone.
* `external/`: Forked or vendored third-party packages (`spatie/laravel-ray`, `pestphp/pest`).

### How WorkspaceManifest Solves It
* **Fixed-Vendor Workspaces:** Declaring `vendor: "alex-kassel"` on a workspace allows packages to be defined as concise slugs (`"workspace-manifest"`). `WorkspaceManifest` automatically expands them into canonical Composer names (`"alex-kassel/workspace-manifest"`).
* **Multi-Vendor Workspaces:** Workspaces without a vendor prefix (`vendor: null`) enforce full vendor/package notation on every entry, preventing namespace ambiguity.

```php
$manifest = WorkspaceManifest::open(base_path('workspace.json'));

// Register an internal company workspace with fixed vendor
$manifest->registerWorkspace('packages', vendor: 'alex-kassel', asDefault: true);
$manifest->addPackage('toolkit', workspace: 'packages'); // Saved as "toolkit"

// Register an external multi-vendor workspace
$manifest->registerWorkspace('vendor-forks', vendor: null);
$manifest->addPackage('spatie/forked-package', workspace: 'vendor-forks');
```

---

## Use Case 3: Autonomous AI Agent Skill Routing & Tool Discovery

### Context
Modern AI development environments utilize specialized skills (e.g. `laravel-best-practices`, `package-audit`, `tailwind-development`). An orchestrator or subagent inspecting a package needs to know immediately which skills apply to that specific package without running deep heuristic scans.

### How WorkspaceManifest Solves It
* Every package definition in `workspace.json` can declare an explicit `skills` array.
* CLI runners and AI coding agents read these skill lists directly through `PackageDefinition::skills`, configuring agent environments with zero overhead.

```php
$manifest = WorkspaceManifest::open(base_path('workspace.json'));

$manifest->updatePackageSkills(
    packageName: 'alex-kassel/manifest-engine',
    skills: ['package-verification', 'laravel-best-practices', 'package-release']
);

// Agent reads configured skills for targeted assistance
$skills = $manifest->getPackage('alex-kassel/manifest-engine')?->skills ?? [];
```

---

## Use Case 4: Workspace Lifecycle Hooks & CI/CD Automation

### Context
Different workspaces within a monorepo may require distinct setup, build, or verification tasks upon checkout or restoration:
* Running `composer install` inside each package directory.
* Executing static analysis or code generation steps before commits.
* Cleaning temporary artifacts or compiling assets.

### How WorkspaceManifest Solves It
* Workspaces support declarative lifecycle hooks (e.g. `pre-restore`, `post-restore`, `pre-commit`).
* Tooling can query and execute these commands in deterministic sequence.

```php
$manifest = WorkspaceManifest::open(base_path('workspace.json'));

// Configure post-restore lifecycle hook
$manifest->setWorkspaceHook(
    workspace: 'packages',
    hook: 'post-restore',
    command: ['composer validate --strict', 'composer install --prefer-dist']
);

$hooks = $manifest->getWorkspaceHooks('packages');
```

---

## Use Case 5: Safe Concurrent Mutations in Multi-Agent / Parallel CLI Workflows

### Context
In automated pipelines, CI jobs, or multi-agent pair programming sessions, multiple background tasks or developer CLI commands may attempt to add, remove, or update packages in `workspace.json` simultaneously.

### Why WorkspaceManifest Guarantees Integrity
* **Atomic Mutate Pipeline:** All write operations (`addPackage`, `removePackage`, `registerWorkspace`, `setDefaultWorkspace`) execute within atomic file-locked transactions provided by `alex-kassel/manifest-engine`.
* **Conflict Prevention (Rule F-03):** Prevents duplicate package names or colliding directory aliases from corrupting the workspace.
* **In-Memory Cache Coherence:** Discards and synchronizes cached DTOs after every mutation, eliminating stale-state read hazards.

```php
$manifest = WorkspaceManifest::open(base_path('workspace.json'));

// Safely remove a package and automatically prune its workspace if empty
$wasRemoved = $manifest->removePackage(
    packageName: 'alex-kassel/legacy-module',
    pruneEmptyWorkspace: true
);
```
