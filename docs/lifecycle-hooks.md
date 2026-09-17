# Workspace Lifecycle Hooks Guide

This document describes how workspace lifecycle hooks are configured, queried, and mutated in `alex-kassel/workspace-manifest`.

---

## 1. Overview & Motivation

When working with multi-package monorepos, different workspaces (e.g. `packages/`, `modules/`, `apps/`) often require specific lifecycle hooks to run after certain tooling events:
* Running a post-install compilation script.
* Running test suites or linting quality gates.
* Triggering custom shell or artisan commands.

`WorkspaceManifest` supports declaring lifecycle hooks per workspace directly inside `workspace.json`.

---

## 2. Manifest Representation

Hooks are stored under the optional `"hooks"` key of a workspace:

```json
{
  "workspaces": {
    "packages": {
      "vendor": "alex-kassel",
      "packages": ["billing"],
      "hooks": {
        "post_install": "composer test",
        "quality_gate": ["composer pint", "composer phpstan"]
      }
    }
  }
}
```

A hook command can be a single command string or an array of command strings.

---

## 3. Query & Mutation API

All hook methods are accessible via `WorkspaceManifest`:

```php
use AlexKassel\WorkspaceManifest\Facades\WorkspaceManifest;

// 1. Query hooks (returns empty array if no hooks configured)
$hooks = WorkspaceManifest::getWorkspaceHooks('packages');
// Returns: ['post_install' => 'composer test']

// 2. Set or update a hook
WorkspaceManifest::setWorkspaceHook('packages', 'post_install', 'composer test');

// Set a multi-command hook
WorkspaceManifest::setWorkspaceHook('packages', 'lint', ['composer pint', 'composer phpstan']);

// 3. Remove a hook
WorkspaceManifest::removeWorkspaceHook('packages', 'lint');
```

### Exception Safety:
If `getWorkspaceHooks()`, `setWorkspaceHook()`, or `removeWorkspaceHook()` is invoked on a workspace that is not registered in `workspace.json`, `WorkspaceNotFoundException` is immediately thrown.
