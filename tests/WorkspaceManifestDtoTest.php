<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\WorkspaceManifest\DTOs\PackageDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceManifestDto;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;

class WorkspaceManifestDtoTest extends TestCase
{
    private string $tempDir;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/workspace_dto_test_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        $this->manifestPath = $this->tempDir.'/workspace.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->manifestPath)) {
            @unlink($this->manifestPath);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_workspace_manifest_dto_implements_manifest_dto_contract(): void
    {
        $dto = new WorkspaceManifestDto;
        $this->assertInstanceOf(ManifestDto::class, $dto);
    }

    public function test_from_array_and_to_array_serialization_roundtrip(): void
    {
        $data = [
            '$schema' => './resources/schema.json',
            'default' => 'packages',
            'repository_url_template' => 'git@github.com:{package}.git',
            'workspaces' => [
                'packages' => [
                    'vendor' => null,
                    'packages' => [
                        'vendor/pkg-one',
                        [
                            'name' => 'vendor/pkg-two',
                            'alias' => 'PkgTwo',
                            'url' => 'https://github.com/custom/pkg-two.git',
                            'skills' => ['package-verification'],
                        ],
                    ],
                ],
                'modules' => [
                    'vendor' => 'acme',
                    'packages' => [
                        'billing',
                    ],
                ],
            ],
            'custom_extension' => ['enabled' => true],
        ];

        $dto = WorkspaceManifestDto::fromArray($data);

        $this->assertSame('./resources/schema.json', $dto->schema);
        $this->assertSame('packages', $dto->default);
        $this->assertSame('git@github.com:{package}.git', $dto->repositoryUrlTemplate);
        $this->assertCount(2, $dto->workspaces);
        $this->assertSame(['enabled' => true], $dto->extra['custom_extension']);

        // Check workspace definitions
        $packagesWs = $dto->getWorkspace('packages');
        $this->assertNotNull($packagesWs);
        $this->assertNull($packagesWs->vendor);
        $this->assertCount(2, $packagesWs->packages);

        $pkg1 = $packagesWs->packages[0];
        $this->assertSame('vendor/pkg-one', $pkg1->name);
        $this->assertNull($pkg1->alias);

        $pkg2 = $packagesWs->packages[1];
        $this->assertSame('vendor/pkg-two', $pkg2->name);
        $this->assertSame('PkgTwo', $pkg2->alias);
        $this->assertSame('https://github.com/custom/pkg-two.git', $pkg2->url);
        $this->assertSame(['package-verification'], $pkg2->skills);

        $modulesWs = $dto->getWorkspace('modules');
        $this->assertNotNull($modulesWs);
        $this->assertSame('acme', $modulesWs->vendor);
        $this->assertTrue($modulesWs->isFixedVendor());

        // Test roundtrip back to array
        $serialized = $dto->toArray();
        $this->assertSame('./resources/schema.json', $serialized['$schema']);
        $this->assertSame('packages', $serialized['default']);
        $this->assertSame('modules', array_keys($serialized['workspaces'])[0]); // Sorted alphabetically
        $this->assertSame('packages', array_keys($serialized['workspaces'])[1]);
        $this->assertSame(['enabled' => true], $serialized['custom_extension']);

        // Check that pkg-one stayed a string and pkg-two stayed an object
        $this->assertSame('vendor/pkg-one', $serialized['workspaces']['packages']['packages'][0]);
        $this->assertIsArray($serialized['workspaces']['packages']['packages'][1]);
        $this->assertSame('vendor/pkg-two', $serialized['workspaces']['packages']['packages'][1]['name']);
        $this->assertSame('PkgTwo', $serialized['workspaces']['packages']['packages'][1]['alias']);
    }

    public function test_dto_package_lookup_and_vendor_resolution(): void
    {
        $dto = WorkspaceManifestDto::fromArray([
            'workspaces' => [
                'modules' => [
                    'vendor' => 'acme',
                    'packages' => [
                        [
                            'name' => 'billing',
                            'alias' => 'BillingModule',
                        ],
                    ],
                ],
                'packages' => [
                    'vendor' => null,
                    'packages' => [
                        'vendor/auth',
                    ],
                ],
            ],
        ]);

        // Find by short name
        $billing = $dto->findPackage('billing');
        $this->assertNotNull($billing);
        $this->assertSame('billing', $billing->name);
        $this->assertSame('acme/billing', $billing->canonicalName('acme'));

        // Find by canonical name
        $billingByCanonical = $dto->findPackage('acme/billing');
        $this->assertSame($billing, $billingByCanonical);

        // Find by alias
        $billingByAlias = $dto->findPackage('BillingModule');
        $this->assertSame($billing, $billingByAlias);

        // Find in specific workspace
        $this->assertNotNull($dto->findPackage('billing', 'modules'));
        $this->assertNull($dto->findPackage('billing', 'packages'));

        // Package presence and workspace finding
        $this->assertTrue($dto->hasPackage('billing'));
        $this->assertTrue($dto->hasPackage('acme/billing'));
        $this->assertTrue($dto->hasPackage('BillingModule'));
        $this->assertFalse($dto->hasPackage('nonexistent'));
        $this->assertSame('modules', $dto->findPackageWorkspace('BillingModule'));
        $this->assertSame('packages', $dto->findPackageWorkspace('vendor/auth'));

        // Package names
        $this->assertEqualsCanonicalizing(['billing', 'vendor/auth'], $dto->packageNames());
        $this->assertSame(['billing'], $dto->packageNames('modules'));
    }

    public function test_workspace_manifest_to_dto_and_save_dto_integration(): void
    {
        $manifest = WorkspaceManifest::open($this->manifestPath)->init();
        $manifest->registerWorkspace('packages');
        $manifest->addPackage('packages', 'acme/core');

        // Hydrate via toDto()
        $dto = $manifest->toDto();
        $this->assertInstanceOf(WorkspaceManifestDto::class, $dto);
        $this->assertTrue($dto->hasWorkspace('packages'));
        $this->assertTrue($dto->hasPackage('acme/core'));

        // Mutate DTO and persist via saveDto()
        $newWs = new WorkspaceDefinition(
            name: 'modules',
            vendor: 'myvendor',
            packages: [
                new PackageDefinition(name: 'invoicing', workspace: 'modules', alias: 'Invoicing'),
            ],
        );

        $updatedDto = $dto->withWorkspace($newWs);
        $manifest->saveDto($updatedDto);

        // Reload manifest from disk and verify
        $freshManifest = WorkspaceManifest::open($this->manifestPath);
        $this->assertTrue($freshManifest->hasWorkspace('modules'));
        $this->assertTrue($freshManifest->hasPackage('myvendor/invoicing'));
        $this->assertSame('modules', $freshManifest->findPackageWorkspace('Invoicing'));

        $pkg = $freshManifest->getPackage('invoicing', 'modules');
        $this->assertNotNull($pkg);
        $this->assertSame('Invoicing', $pkg->alias);
    }
}
