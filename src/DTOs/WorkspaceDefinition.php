<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\DTOs;

final class WorkspaceDefinition
{
    /**
     * @var array<int, PackageDefinition>
     */
    public const DEFAULT_EMPTY_PACKAGES = [];

    /**
     * @param  array<int, PackageDefinition>  $packages
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $vendor = null,
        public readonly array $packages = self::DEFAULT_EMPTY_PACKAGES,
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
        $lower = strtolower($nameOrAlias);

        foreach ($this->packages as $pkg) {
            $canonical = strtolower($pkg->canonicalName($this->vendor));

            if (
                strtolower($pkg->name) === $lower
                || $canonical === $lower
                || ($pkg->alias !== null && strtolower($pkg->alias) === $lower)
            ) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * Convert to raw manifest workspace structure.
     *
     * @return array{vendor: ?string, packages: array<int, string|array<string, mixed>>}
     */
    public function toManifestArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'packages' => array_map(fn (PackageDefinition $pkg) => $pkg->toManifestEntry(), $this->packages),
        ];
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
