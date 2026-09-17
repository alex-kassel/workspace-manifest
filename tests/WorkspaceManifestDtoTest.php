<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\ManifestEngine\Contracts\ManifestDto;
use AlexKassel\WorkspaceManifest\DTOs\PackageDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceManifestDto;
use AlexKassel\WorkspaceManifest\Exceptions\PackageConflictException;

class WorkspaceManifestDtoTest extends TestCase
{
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
                    'hooks' => [
                        'post-install' => 'scripts/post-install.sh',
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
        $this->assertNull($packagesWs->hooks);
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
        $this->assertSame(['post-install' => 'scripts/post-install.sh'], $modulesWs->hooks);

        // Test roundtrip back to array
        $serialized = $dto->toArray();
        $this->assertSame('./resources/schema.json', $serialized['$schema']);
        $this->assertSame('packages', $serialized['default']);
        $this->assertSame('modules', array_keys($serialized['workspaces'])[0]); // Sorted alphabetically
        $this->assertSame('packages', array_keys($serialized['workspaces'])[1]);
        $this->assertSame(['enabled' => true], $serialized['custom_extension']);
        $this->assertSame(['post-install' => 'scripts/post-install.sh'], $serialized['workspaces']['modules']['hooks']);

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

    public function test_workspace_definition_mutation_methods(): void
    {
        $ws = new WorkspaceDefinition(name: 'packages', vendor: 'acme');

        // withVendor
        $wsWithVendor = $ws->withVendor('newvendor');
        $this->assertSame('newvendor', $wsWithVendor->vendor);

        // withPackage (normal addition and canonical sorting)
        $pkgZ = new PackageDefinition(name: 'zeta', workspace: 'packages');
        $pkgA = new PackageDefinition(name: 'alpha', workspace: 'packages', alias: 'A-Alpha');
        $ws = $ws->withPackage($pkgZ)->withPackage($pkgA);

        $this->assertCount(2, $ws->packages);
        $this->assertSame('alpha', $ws->packages[0]->name); // A-Alpha sorts before zeta
        $this->assertSame('zeta', $ws->packages[1]->name);

        // withPackage conflict check (rule F-03)
        $conflictingPkg = new PackageDefinition(name: 'other', workspace: 'packages', alias: 'zeta');
        $this->expectException(PackageConflictException::class);
        $ws->withPackage($conflictingPkg);
    }

    public function test_workspace_definition_without_package(): void
    {
        $ws = new WorkspaceDefinition(
            name: 'modules',
            vendor: 'acme',
            packages: [
                new PackageDefinition(name: 'billing', workspace: 'modules', alias: 'Billing'),
                new PackageDefinition(name: 'invoicing', workspace: 'modules'),
            ],
        );

        // Remove by alias
        [$ws1, $removed1] = $ws->withoutPackage('Billing');
        $this->assertTrue($removed1);
        $this->assertCount(1, $ws1->packages);
        $this->assertSame('invoicing', $ws1->packages[0]->name);

        // Remove by canonical name
        [$ws2, $removed2] = $ws->withoutPackage('acme/invoicing');
        $this->assertTrue($removed2);
        $this->assertCount(1, $ws2->packages);
        $this->assertSame('billing', $ws2->packages[0]->name);

        // Non-existent package
        [$ws3, $removed3] = $ws->withoutPackage('non-existent');
        $this->assertFalse($removed3);
        $this->assertCount(2, $ws3->packages);
    }

    public function test_workspace_manifest_dto_mutation_methods(): void
    {
        $dto = new WorkspaceManifestDto;

        // withWorkspace
        $dto = $dto->withWorkspace('packages', asDefault: true);
        $this->assertTrue($dto->hasWorkspace('packages'));
        $this->assertSame('packages', $dto->default);

        // withWorkspaceVendor
        $dto = $dto->withWorkspaceVendor('packages', 'acme');
        $this->assertSame('acme', $dto->getWorkspace('packages')?->vendor);

        // withPackage
        $pkg = new PackageDefinition(name: 'billing', workspace: 'packages');
        $dto = $dto->withPackage('packages', $pkg);
        $this->assertTrue($dto->hasPackage('acme/billing'));

        // withoutPackage with pruneEmptyWorkspace
        [$dtoWithoutPkg, $removed] = $dto->withoutPackage('acme/billing', pruneEmptyWorkspace: true);
        $this->assertTrue($removed);
        $this->assertFalse($dtoWithoutPkg->hasPackage('acme/billing'));
        $this->assertFalse($dtoWithoutPkg->hasWorkspace('packages')); // Pruned!
        $this->assertNull($dtoWithoutPkg->default);

        // withDefault
        $dto = $dto->withDefault('custom-default');
        $this->assertSame('custom-default', $dto->default);

        // withRepositoryUrlTemplate
        $dto = $dto->withRepositoryUrlTemplate('https://custom.repo/{package}.git');
        $this->assertSame('https://custom.repo/{package}.git', $dto->repositoryUrlTemplate);

        // withoutWorkspace
        $dto2 = (new WorkspaceManifestDto)->withWorkspace('w1', asDefault: true)->withWorkspace('w2');
        $dto2 = $dto2->withoutWorkspace('w1', reassignDefault: true);
        $this->assertFalse($dto2->hasWorkspace('w1'));
        $this->assertSame('w2', $dto2->default);
    }
}
