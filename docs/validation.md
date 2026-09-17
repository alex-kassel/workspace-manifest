# WorkspaceValidator & Domain Validation Guide

This document covers `AlexKassel\WorkspaceManifest\Validation\WorkspaceValidator`, the **Single Source of Truth (SSOT)** for validating package names, workspace paths, vendor slugs, aliases, and repository templates.

---

## 1. Overview & Architecture

Consumer tools (such as `workspace-development-toolkit`, CLI make commands, and terminal prompts) should never duplicate package naming rules or path traversal checks.

`WorkspaceValidator` provides standalone, pure static validation methods that return structured `ValidationResult` objects:

```php
use AlexKassel\WorkspaceManifest\Validation\WorkspaceValidator;

$result = WorkspaceValidator::validatePackageName($userInput, $workspaceVendor);

if ($result->isInvalid()) {
    echo "Error: " . $result->errorMessage() . "\n";
    echo "Suggestion: " . $result->suggestion() . "\n";
}
```

---

## 2. Package Name Validation (`validatePackageName`)

Validates package naming against Composer standards (`/^[a-z0-9]([_.-]?[a-z0-9]+)*$/`).

```php
WorkspaceValidator::validatePackageName(
    string $input,
    ?string $workspaceVendor = null
): PackageValidationResult
```

### Supported Forms:

#### A. Full Canonical Name (`vendor/package`)
* Valid: `"acme/billing-core"`, `"spatie/laravel-ray"`, `"org_1/tool.sub"`
* Invalid: `"Acme/Billing"` (contains uppercase), `"acme/pkg/sub"` (multiple slashes).
* Suggested Fix: Automatically converts to lowercase and converts illegal characters to dashes using `Str::slug()`.

#### B. Short Name with Known Workspace Vendor (`$workspaceVendor !== null`)
* If workspace has fixed vendor `"acme"` and user inputs `"billing"`:
  * Result: `isValid() === true`
  * `canonicalName`: `"acme/billing"`
  * `shortName`: `"billing"`
  * `storedName`: `"billing"`

#### C. Short Name without Workspace Vendor (`$workspaceVendor === null`)
* If workspace has no vendor and user inputs `"billing"`:
  * Result: `isValid() === false`
  * Error: `"Package name [billing] requires a vendor prefix in 'vendor/package' format when workspace has no default vendor."`
  * Suggestion: `"my-vendor/billing"`

### Return Structure (`PackageValidationResult`):
* `isValid(): bool`
* `errorMessage(): ?string`
* `suggestion(): ?string`
* `vendor`: parsed vendor slug (e.g. `"acme"`)
* `package`: parsed short package name (e.g. `"billing"`)
* `canonicalName`: full `"vendor/package"` name
* `shortName`: short package name
* `storedName`: the name that should be saved to `workspace.json`

---

## 3. Vendor Slug Validation (`validateVendorSlug`)

Validates vendor namespace slugs for workspaces.

```php
WorkspaceValidator::validateVendorSlug(?string $input, bool $nullable = true): ValidationResult
```

* When `$nullable === true` and input is `null`: Returns valid (`normalized() === null`).
* When provided: Must match Composer segment pattern (`/^[a-z0-9]([_.-]?[a-z0-9]+)*$/`).
* Suggestion: Automatically generates a slug if invalid (`"Acme Corp!"` -> `"acme-corp"`).

---

## 4. Workspace Path Validation (`validateWorkspacePath`)

Ensures workspace directory paths are secure, relative, and traversal-free.

```php
WorkspaceValidator::validateWorkspacePath(string $input): ValidationResult
```

* **Prevents Directory Traversal:** Disallows paths that escape the root (`"../../outside"`). Resolves inner dot segments (`"packages/temp/../modules"` -> `"packages/modules"`).
* **Prevents Absolute Paths:** Rejects paths starting with `/` or drive letters (`"C:\"`).
* **Prevents Empty Paths:** Rejects `""`, `"."`, or root.

---

## 5. Package Alias Validation (`validatePackageAlias`)

Validates directory aliases for packages.

```php
WorkspaceValidator::validatePackageAlias(?string $input): ValidationResult
```

* Allows `null` or empty string (no alias).
* Prohibits slashes (`/`, `\`) and traversal symbols (`.`, `..`).
* Allows uppercase / PascalCase names (`"BillingService"`).

---

## 6. Repository URL Template Validation (`validateRepositoryUrlTemplate`)

Validates Git clone templates:

```php
WorkspaceValidator::validateRepositoryUrlTemplate(string $input): ValidationResult
```

* Ensures the template is non-empty.
* Guarantees the presence of the `{package}` substitution placeholder:
  * Valid: `"git@github.com:{package}.git"`, `"https://gitlab.com/{package}.git"`
  * Invalid: `"git@github.com:my-org/repo.git"` (missing `{package}`).

---

## 7. Integration with Interactive Prompts (`Laravel\Prompts`)

`WorkspaceValidator` methods return `?string` error messages directly compatible with `Laravel\Prompts`:

```php
use AlexKassel\WorkspaceManifest\Validation\WorkspaceValidator;
use function Laravel\Prompts\text;

$packageName = text(
    label: 'Package name',
    placeholder: 'e.g. acme/billing',
    validate: fn ($value) => WorkspaceValidator::validatePackageName($value, $workspaceVendor)->errorMessage()
);

$alias = text(
    label: 'Directory alias (optional)',
    validate: fn ($value) => WorkspaceValidator::validatePackageAlias($value)->errorMessage()
);
```
