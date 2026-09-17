<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\DTOs;

use AlexKassel\WorkspaceManifest\Exceptions\PackageConflictException;

final class WorkspaceDefinition
{
    /**
     * @var array<int, PackageDefinition>
     */
    public const DEFAULT_EMPTY_PACKAGES = [];

    /**
     * @var array<string, mixed>|null
     */
    public const DEFAULT_HOOKS = null;

    /**
     * @param  array<int, PackageDefinition>  $packages
     * @param  array<string, mixed>|null  $hooks
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $vendor = null,
        public readonly array $packages = self::DEFAULT_EMPTY_PACKAGES,
        public readonly ?array $hooks = self::DEFAULT_HOOKS,
    ) {}

    /**
     * Create a WorkspaceDefinition from raw manifest workspace data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromManifest(string $name, array $data): self
    {
        $vendor = isset($data['vendor']) && is_string($data['vendor']) ? $data['vendor'] : null;
        $rawPackages = (array) ($data['packages'] ?? self::DEFAULT_EMPTY_PACKAGES);
        $hooks = isset($data['hooks']) && is_array($data['hooks']) ? $data['hooks'] : self::DEFAULT_HOOKS;

        $packages = [];
        foreach ($rawPackages as $rawPkg) {
            if (is_string($rawPkg) || is_array($rawPkg)) {
                $packages[] = PackageDefinition::fromManifest($name, $rawPkg);
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
                || $pkg->canonicalName($this->vendor) === $target
                || ($pkg->alias !== null && strcasecmp($pkg->alias, $nameOrAlias) === 0)
            ) {
                return $pkg;
            }
        }

        return null;
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

        if (str_contains($cleanPackageName, '/')) {
            [$inputVendor, $inputShortName] = explode('/', $cleanPackageName, 2);
            $canonicalNewName = $cleanPackageName;
            $storedName = ($wsVendor !== null && $inputVendor === $wsVendor)
                ? $inputShortName
                : $cleanPackageName;
        } else {
            $canonicalNewName = $wsVendor !== null ? "{$wsVendor}/{$cleanPackageName}" : $cleanPackageName;
            $storedName = $cleanPackageName;
        }

        foreach ($this->packages as $item) {
            $existingCanonical = $item->canonicalName($wsVendor);

            if ($existingCanonical === $canonicalNewName) {
                continue;
            }

            if ($item->alias !== null && (
                strcasecmp($item->alias, $canonicalNewName) === 0 ||
                strcasecmp($item->alias, $storedName) === 0
            )) {
                throw new PackageConflictException(
                    $canonicalNewName,
                    $item->name,
                    "Cannot record package [{$canonicalNewName}]: it conflicts with the alias of existing package [{$item->name}]."
                );
            }

            if ($cleanAlias !== null) {
                if (
                    strcasecmp($cleanAlias, $existingCanonical) === 0 ||
                    strcasecmp($cleanAlias, $item->name) === 0
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
            name: $storedName,
            workspace: $this->name,
            alias: $cleanAlias,
            url: $cleanUrl,
            skills: $package->skills,
        );

        $packages = $this->packages;
        $replaced = false;
        foreach ($packages as $index => $item) {
            if ($item->canonicalName($wsVendor) === $canonicalNewName) {
                $packages[$index] = $normalizedPackage;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $packages[] = $normalizedPackage;
        }

        usort($packages, fn (PackageDefinition $a, PackageDefinition $b) => strcasecmp($a->effectiveDirectory(), $b->effectiveDirectory()));

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
                $pkg->canonicalName($wsVendor) === $targetCanonical
                || $pkg->name === $cleanTarget
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
     * Convert to raw manifest workspace structure.
     *
     * @return array{vendor: ?string, packages: array<int, string|array<string, mixed>>, hooks?: array<string, mixed>}
     */
    public function toManifestArray(): array
    {
        $data = [
            'vendor' => $this->vendor,
            'packages' => array_map(fn (PackageDefinition $pkg) => $pkg->toManifestEntry(), $this->packages),
        ];

        if ($this->hooks !== null) {
            $data['hooks'] = $this->hooks;
        }

        return $data;
    }

    /**
     * Convert to array representation.
     *
     * @return array{vendor: ?string, packages: array<int, array{name: string, workspace: string, alias: ?string, url: ?string, skills: array<string>}>, hooks: ?array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'packages' => array_map(fn (PackageDefinition $pkg) => $pkg->toArray(), $this->packages),
            'hooks' => $this->hooks,
        ];
    }
}
