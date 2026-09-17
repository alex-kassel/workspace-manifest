# Workspace JSON Schema Specification Guide

This document describes the schema definition, Draft-07 specification, and IDE autocomplete setup for `workspace.json`.

---

## 1. Schema Architecture

`AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema` implements `AlexKassel\ManifestEngine\Contracts\ManifestSchema` using Laravel 13's native `Illuminate\JsonSchema\JsonSchema` builder.

It produces a standard Draft-07 JSON Schema published at:
`https://raw.githubusercontent.com/alex-kassel/workspace-manifest/main/resources/schema.json`

---

## 2. Specification Structure

```json
{
  "$schema": "https://raw.githubusercontent.com/alex-kassel/workspace-manifest/main/resources/schema.json",
  "default": "packages",
  "repository_url_template": "git@github.com:{package}.git",
  "workspaces": {
    "packages": {
      "vendor": "alex-kassel",
      "packages": [
        "billing",
        {
          "name": "catalog",
          "alias": "CatalogModule",
          "url": "git@github.com:alex-kassel/catalog.git",
          "skills": ["laravel-best-practices", "package-audit"]
        }
      ],
      "hooks": {
        "post_install": "composer test"
      }
    }
  }
}
```

### Property Taxonomy:

| Property | Type | Description |
| :--- | :--- | :--- |
| `"$schema"` | `string` | Location of the JSON Schema specification. |
| `"default"` | `string\|null` | Default workspace directory. |
| `"repository_url_template"` | `string` | Git remote URL template with `{package}` placeholder. |
| `"workspaces"` | `object` | Map of workspace directory names to workspace configurations. |
| `"workspaces.<dir>.vendor"` | `string\|null` | Optional vendor prefix for flat package directories. |
| `"workspaces.<dir>.packages"` | `array` | List of package entries (string or object). |
| `"workspaces.<dir>.hooks"` | `object` | Workspace lifecycle triggers and scripts. |

### Package Entry Specification:
A package entry can be either:
1. A **string** representing the package name: `"acme/billing"` or `"billing"`.
2. An **object** with attributes:
   * `"name"` (`string`, required): Package name.
   * `"alias"` (`string|null`): Directory name alias.
   * `"url"` (`string|null`): Custom Git remote clone URL.
   * `"skills"` (`array<string>`): Agent skills assigned to this package.

---

## 3. IDE Integration (VS Code, PhpStorm, Cursor)

When `"$schema"` is included in `workspace.json`, editors provide:
* Full autocompletion of keys (`default`, `repository_url_template`, `workspaces`, `packages`, `hooks`, `skills`).
* Inline documentation tooltips explaining what each field does.
* Instant syntax error highlighting if unknown types are provided.
