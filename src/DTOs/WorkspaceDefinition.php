<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\DTOs;

use AlexKassel\WorkspaceManifest\Exceptions\PackageConflictException;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final class WorkspaceDefinition implements Arrayable
{
    /**
     * @param  array<int, PackageDefinition>  $packages
     * @param  array<string, mixed>|null  $hooks
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $vendor = null,
        public readonly array $packages = [],
        public readonly ?array $hooks = null,
    ) {}

    /**
     * Create a WorkspaceDefinition from raw manifest workspace data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromManifest(string $name, array $data): self
    {
        $vendor = isset($data['vendor']) && is_string($data['vendor']) ? $data['vendor'] : null;
        $rawPackages = (array) ($data['packages'] ?? []);
        $hooks = isset($data['hooks']) && is_array($data['hooks']) ? $data['hooks'] : null;

        $packages = [];
        foreach ($rawPackages as $rawPkg) {
            if (is_string($rawPkg) || is_array($rawPkg)) {
                $packages[] = PackageDefinition::fromManifest($name, $rawPkg, $vendor);
            }
        }

        return new self(
            name: $name,
            vendor: $vendor,
            packages: $packages,
            hooks: $hooks,
        );
    }

    /**
     * Determine if this workspace enforces a fixed vendor namespace.
     */
    public function isFixedVendor(): bool
    {
        return $this->vendor !== null && trim($this->vendor) !== '';
    }

    /**
     * Get list of package names in this workspace.
     *
     * @return array<int, string>
     */
    public function packageNames(): array
    {
        return array_map(fn (PackageDefinition $pkg) => $pkg->name, $this->packages);
    }

    /**
     * Find a package by its local name, canonical name, or alias (case-insensitive).
     */
    public function findPackage(string $nameOrAlias): ?PackageDefinition
    {
        $target = strtolower(trim($nameOrAlias));

        foreach ($this->packages as $pkg) {
            if (
                $pkg->name === $target
                || ($this->vendor !== null && strtolower("{$this->vendor}/{$target}") === $pkg->name)
                || ($pkg->alias !== null && strcasecmp($pkg->alias, $nameOrAlias) === 0)
            ) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * Determine if a package exists in this workspace by name, short name, or alias.
     */
    public function hasPackage(string $nameOrAlias): bool
    {
        return $this->findPackage($nameOrAlias) !== null;
    }

    /**
     * Return a new instance with updated vendor prefix.
     */
    public function withVendor(?string $vendor): self
    {
        $cleanVendor = $vendor !== null && trim($vendor) !== '' ? strtolower(trim($vendor)) : null;

        return new self(
            name: $this->name,
            vendor: $cleanVendor,
            packages: $this->packages,
            hooks: $this->hooks,
        );
    }

    /**
     * Return a new instance with updated hooks.
     *
     * @param  array<string, mixed>|null  $hooks
     */
    public function withHooks(?array $hooks): self
    {
        return new self(
            name: $this->name,
            vendor: $this->vendor,
            packages: $this->packages,
            hooks: $hooks,
        );
    }

    /**
     * Add or update a package definition in this workspace.
     * Enforces alias/name conflict rules and alphabetical sorting by effective directory.
     *
     * @throws PackageConflictException
     */
    public function withPackage(PackageDefinition $package): self
    {
        $cleanPackageName = strtolower(trim($package->name));
        $cleanAlias = $package->alias !== null && trim($package->alias) !== '' ? trim($package->alias) : null;
        $cleanUrl = $package->url !== null && trim($package->url) !== '' ? trim($package->url) : null;
        $wsVendor = $this->vendor;

        $canonicalNewName = (! str_contains($cleanPackageName, '/') && $wsVendor !== null)
            ? "{$wsVendor}/{$cleanPackageName}"
            : $cleanPackageName;

        $shortNewName = str_contains($canonicalNewName, '/')
            ? explode('/', $canonicalNewName, 2)[1]
            : $canonicalNewName;

        foreach ($this->packages as $item) {
            if ($item->name === $canonicalNewName) {
                continue;
            }

            if ($item->alias !== null && (
                strcasecmp($item->alias, $canonicalNewName) === 0 ||
                strcasecmp($item->alias, $shortNewName) === 0
            )) {
                throw new PackageConflictException(
                    $canonicalNewName,
                    $item->name,
                    "Cannot record package [{$canonicalNewName}]: it conflicts with the alias of existing package [{$item->name}]."
                );
            }

            if ($cleanAlias !== null) {
                if (
                    strcasecmp($cleanAlias, $item->name) === 0 ||
                    strcasecmp($cleanAlias, $item->shortName()) === 0
                ) {
                    throw new PackageConflictException(
                        $cleanAlias,
                        $item->name,
                        "Cannot use alias [{$cleanAlias}]: it conflicts with the name of existing package [{$item->name}]."
                    );
                }

                if ($item->alias !== null && strcasecmp($cleanAlias, $item->alias) === 0) {
                    throw new PackageConflictException(
                        $cleanAlias,
                        $item->name,
                        "Cannot use alias [{$cleanAlias}]: it conflicts with the alias of existing package [{$item->name}]."
                    );
                }
            }
        }

        $normalizedPackage = new PackageDefinition(
            name: $canonicalNewName,
            workspace: $this->name,
            alias: $cleanAlias,
            url: $cleanUrl,
            skills: $package->skills,
        );

        $packages = $this->packages;
        $replaced = false;
        foreach ($packages as $index => $item) {
            if ($item->name === $canonicalNewName) {
                $packages[$index] = $normalizedPackage;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $packages[] = $normalizedPackage;
        }

        usort($packages, fn (PackageDefinition $a, PackageDefinition $b) => strcasecmp($a->effectiveDirectory($this->vendor), $b->effectiveDirectory($this->vendor)));

        return new self(
            name: $this->name,
            vendor: $this->vendor,
            packages: $packages,
            hooks: $this->hooks,
        );
    }

    /**
     * Remove a package by name, canonical name, or alias.
     *
     * @return array{0: self, 1: bool} Tuple of [newWorkspaceDefinition, wasRemoved]
     */
    public function withoutPackage(string $packageName): array
    {
        $cleanTarget = strtolower(trim($packageName));
        $wsVendor = $this->vendor;
        $targetCanonical = (! str_contains($cleanTarget, '/') && $wsVendor !== null)
            ? "{$wsVendor}/{$cleanTarget}"
            : $cleanTarget;

        $filtered = [];
        $removed = false;

        foreach ($this->packages as $pkg) {
            if (
                $pkg->name === $targetCanonical
                || $pkg->name === $cleanTarget
                || $pkg->shortName() === $cleanTarget
                || ($pkg->alias !== null && strcasecmp($pkg->alias, $packageName) === 0)
            ) {
                $removed = true;

                continue;
            }

            $filtered[] = $pkg;
        }

        $newWs = $removed
            ? new self($this->name, $this->vendor, $filtered, $this->hooks)
            : $this;

        return [$newWs, $removed];
    }

    /**
     * Return a new instance with an added or updated lifecycle hook.
     *
     * @param  string|array<int, string>  $command
     */
    public function withHook(string $hook, string|array $command): self
    {
        $hooks = $this->hooks ?? [];
        $hooks[$hook] = $command;

        return new self(
            name: $this->name,
            vendor: $this->vendor,
            packages: $this->packages,
            hooks: $hooks,
        );
    }

    /**
     * Return a new instance with a lifecycle hook removed.
     */
    public function withoutHook(string $hook): self
    {
        if ($this->hooks === null || ! array_key_exists($hook, $this->hooks)) {
            return $this;
        }

        $hooks = $this->hooks;
        unset($hooks[$hook]);

        return new self(
            name: $this->name,
            vendor: $this->vendor,
            packages: $this->packages,
            hooks: empty($hooks) ? null : $hooks,
        );
    }

    /**
     * Convert to raw manifest workspace structure.
     *
     * @return array{vendor: ?string, packages: array<int, string|array<string, mixed>>, hooks?: array<string, mixed>}
     */
    public function toManifestArray(): array
    {
        $data = [
            'vendor' => $this->vendor,
            'packages' => array_map(fn (PackageDefinition $pkg) => $pkg->toManifestEntry($this->vendor), $this->packages),
        ];

        if ($this->hooks !== null) {
            $data['hooks'] = $this->hooks;
        }

        return $data;
    }

    /**
     * Convert to array representation (Arrayable contract).
     *
     * @return array{name: string, vendor: ?string, packages: array<int, array{name: string, alias?: string, url?: string, skills?: array<string>}>, hooks: ?array<string, mixed>}
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'vendor' => $this->vendor,
            'packages' => array_map(fn (PackageDefinition $pkg) => $pkg->toArray(), $this->packages),
            'hooks' => $this->hooks,
        ];

        return $data;
    }
}
