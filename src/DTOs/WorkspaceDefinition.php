<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\DTOs;

class WorkspaceDefinition
{
    /**
     * @param  array<int, PackageDefinition>  $packages
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $vendor = null,
        public readonly array $packages = [],
    ) {}

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
     * Find a package by its local name or alias.
     */
    public function findPackage(string $nameOrAlias): ?PackageDefinition
    {
        foreach ($this->packages as $pkg) {
            if ($pkg->name === $nameOrAlias || ($pkg->alias !== null && $pkg->alias === $nameOrAlias)) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * Convert to array representation.
     *
     * @return array{vendor: ?string, packages: array<int, array{name: string, workspace: string, alias: ?string, url: ?string, skills: array<string>}>}
     */
    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'packages' => array_map(fn (PackageDefinition $pkg) => $pkg->toArray(), $this->packages),
        ];
    }
}
