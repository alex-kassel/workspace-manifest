<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\WorkspaceManifest\DTOs\PackageDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceDefinition;
use AlexKassel\WorkspaceManifest\DTOs\WorkspaceManifestDto;
use AlexKassel\WorkspaceManifest\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidPackageAliasException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidPackageNameException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidRepositoryUrlTemplateException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidVendorSlugException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceManifest\Exceptions\PackageConflictException;
use AlexKassel\WorkspaceManifest\Exceptions\WorkspaceAlreadyExistsException;
use AlexKassel\WorkspaceManifest\Exceptions\WorkspaceNotFoundException;
use AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;
use Illuminate\Support\Facades\File;

class WorkspaceManifestTest extends TestCase
{
    protected string $testDir;

    protected string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = storage_path('framework/testing/workspace_manifest_'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->testDir);
        $this->manifestPath = $this->testDir.'/workspace.json';
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }

    public function test_init_and_default_structure(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $this->assertFalse($wm->exists());

        $wm->init();
        $this->assertTrue($wm->exists());
        $this->assertNull($wm->getDefaultWorkspace());
        $this->assertEquals('git@github.com:{package}.git', $wm->getRepositoryUrlTemplate());
        $this->assertSame([], $wm->getWorkspaces());
        $this->assertSame([], $wm->getWorkspaceNames());
        $this->assertSame([], $wm->getWorkspaceDefinitions());
        $this->assertSame(WorkspaceSchema::DEFAULT_SCHEMA_PATH, $wm->manifest()->get('$schema'));
    }

    public function test_path_normalization_and_validation(): void
    {
        $this->assertSame('packages', WorkspaceManifest::normalizeWorkspacePath('packages'));
        $this->assertSame('packages/sub', WorkspaceManifest::normalizeWorkspacePath('packages/sub/'));
        $this->assertSame('packages/sub', WorkspaceManifest::normalizeWorkspacePath('packages\\sub\\'));
        $this->assertSame('packages/sub', WorkspaceManifest::normalizeWorkspacePath('packages/temp/../sub'));
        $this->assertSame('packages/@scoped/sub', WorkspaceManifest::normalizeWorkspacePath('packages/@scoped/sub'));
        $this->assertSame('packages/~shared', WorkspaceManifest::normalizeWorkspacePath('packages/~shared'));
        $this->assertSame('packages/+modules', WorkspaceManifest::normalizeWorkspacePath('packages/+modules'));

        // Rejects path traversal escaping workspace root
        try {
            WorkspaceManifest::normalizeWorkspacePath('../evil-path');
            $this->fail('Expected InvalidWorkspacePathException for ../evil-path');
        } catch (InvalidWorkspacePathException $e) {
            $this->assertInstanceOf(InvalidWorkspacePathException::class, $e);
        }

        try {
            WorkspaceManifest::normalizeWorkspacePath('packages/../../evil');
            $this->fail('Expected InvalidWorkspacePathException for packages/../../evil');
        } catch (InvalidWorkspacePathException $e) {
            $this->assertInstanceOf(InvalidWorkspacePathException::class, $e);
        }

        // Rejects absolute paths
        try {
            WorkspaceManifest::normalizeWorkspacePath('/packages');
            $this->fail('Expected InvalidWorkspacePathException for /packages');
        } catch (InvalidWorkspacePathException $e) {
            $this->assertInstanceOf(InvalidWorkspacePathException::class, $e);
        }

        try {
            WorkspaceManifest::normalizeWorkspacePath('C:\\packages');
            $this->fail('Expected InvalidWorkspacePathException for C:\\packages');
        } catch (InvalidWorkspacePathException $e) {
            $this->assertInstanceOf(InvalidWorkspacePathException::class, $e);
        }

        // Rejects empty / root paths
        $this->expectException(InvalidWorkspacePathException::class);
        WorkspaceManifest::normalizeWorkspacePath('.');
    }

    public function test_register_and_unregister_workspace(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        $wm->registerWorkspace('packages', vendor: 'alex-kassel', asDefault: true);
        $wm->registerWorkspace('modules', vendor: 'alex-kassel');

        $this->assertTrue($wm->hasWorkspace('packages'));
        $this->assertTrue($wm->hasWorkspace('modules'));
        $this->assertFalse($wm->hasWorkspace('non-existent'));
        $this->assertSame(['modules', 'packages'], $wm->getWorkspaceNames());
        $this->assertSame('packages', $wm->getDefaultWorkspace());
        $this->assertSame('alex-kassel', $wm->getWorkspaceVendor('packages'));

        $definitions = $wm->getWorkspaceDefinitions();
        $this->assertArrayHasKey('packages', $definitions);
        $this->assertInstanceOf(WorkspaceDefinition::class, $definitions['packages']);
        $this->assertSame('alex-kassel', $definitions['packages']->vendor);

        $wm->setWorkspaceVendor('modules', 'new-vendor');
        $this->assertSame('new-vendor', $wm->getWorkspaceVendor('modules'));

        $wm->setDefaultWorkspace('modules');
        $this->assertSame('modules', $wm->getDefaultWorkspace());

        $wm->setRepositoryUrlTemplate('https://gitlab.com/{package}.git');
        $this->assertSame('https://gitlab.com/{package}.git', $wm->getRepositoryUrlTemplate());

        $wm->unregisterWorkspace('modules', reassignDefault: true);
        $this->assertFalse($wm->hasWorkspace('modules'));
        $this->assertSame('packages', $wm->getDefaultWorkspace());
    }

    public function test_add_packages_string_and_structured(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('packages');

        $wm->addPackage('packages', 'vendor/pkg-simple');
        $this->assertTrue($wm->hasPackage('vendor/pkg-simple'));
        $this->assertSame('packages', $wm->findPackageWorkspace('vendor/pkg-simple'));

        $pkg = $wm->getPackage('vendor/pkg-simple');
        $this->assertInstanceOf(PackageDefinition::class, $pkg);
        $this->assertSame('vendor/pkg-simple', $pkg->name);
        $this->assertNull($pkg->alias);
        $this->assertSame('vendor/pkg-simple', $pkg->effectiveDirectory());

        // Add structured package with alias and skills
        $wm->addPackage(
            workspace: 'packages',
            packageName: 'vendor/pkg-rich',
            alias: 'RichPackage',
            url: 'git@custom.repo/pkg-rich.git',
            skills: ['skill-1', 'skill-2']
        );

        $this->assertTrue($wm->hasPackage('vendor/pkg-rich'));
        $this->assertTrue($wm->hasPackage('RichPackage'));
        $rich = $wm->getPackage('vendor/pkg-rich');
        $this->assertInstanceOf(PackageDefinition::class, $rich);
        $this->assertSame('RichPackage', $rich->alias);
        $this->assertSame('RichPackage', $rich->effectiveDirectory());
        $this->assertSame('git@custom.repo/pkg-rich.git', $rich->url);
        $this->assertSame(['skill-1', 'skill-2'], $rich->skills);

        // Deduplication test: re-adding does not create duplicate entries
        $wm->addPackage('packages', 'vendor/pkg-simple');
        $this->assertCount(2, $wm->getPackageNames('packages'));
        $this->assertCount(2, $wm->getPackageNames());

        $raw = $wm->getRawPackages('packages');
        $this->assertCount(2, $raw);
    }

    public function test_conflict_prevention_rule_f03(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('packages', vendor: 'acme');
        $wm->addPackage('packages', 'acme/foo', alias: 'bar');

        // Collision 1: New package name matches existing alias
        $this->expectException(PackageConflictException::class);
        $wm->addPackage('packages', 'acme/bar');
    }

    public function test_alias_conflict_with_existing_package_name(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('packages', vendor: 'acme');
        $wm->addPackage('packages', 'acme/foo');

        // Collision 2: New package alias matches existing package name
        $this->expectException(PackageConflictException::class);
        $wm->addPackage('packages', 'acme/bar', alias: 'foo');
    }

    public function test_register_package_alias_and_skills_update(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('packages');
        $wm->addPackage('packages', 'acme/tool');

        $wm->registerPackageAlias('packages', 'acme/tool', 'ToolMaster');
        $pkg = $wm->getPackage('acme/tool');
        $this->assertNotNull($pkg);
        $this->assertSame('ToolMaster', $pkg->alias);

        $wm->updatePackageSkills('packages', 'acme/tool', ['laravel-best-practices', 'testing']);
        $pkgUpdated = $wm->getPackage('acme/tool');
        $this->assertNotNull($pkgUpdated);
        $this->assertSame('ToolMaster', $pkgUpdated->alias);
        $this->assertSame(['laravel-best-practices', 'testing'], $pkgUpdated->skills);
    }

    public function test_remove_package_with_empty_workspace_prune(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('temp-ws');

        $wm->addPackage('temp-ws', 'vendor/temp-pkg');
        $this->assertTrue($wm->hasWorkspace('temp-ws'));
        $this->assertTrue($wm->hasPackage('vendor/temp-pkg'));

        // Removing non-existent package returns false
        $this->assertFalse($wm->removePackage('vendor/non-existent'));

        $removed = $wm->removePackage('vendor/temp-pkg', pruneEmptyWorkspace: true);
        $this->assertTrue($removed);
        $this->assertFalse($wm->hasPackage('vendor/temp-pkg'));
        $this->assertFalse($wm->hasWorkspace('temp-ws'));
    }

    public function test_json_schema_export_and_specification(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $schema = $wm->manifest()->exportJsonSchema();

        $this->assertIsArray($schema);
        $this->assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema'] ?? null);
        $this->assertSame('WorkspaceManifest', $schema['title'] ?? null);
        $this->assertArrayHasKey('workspaces', $schema['properties'] ?? []);
    }

    public function test_fixed_vendor_workspace_package_handling_and_different_vendor_support(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('modules', vendor: 'acme');

        // 1. Add short package name to fixed-vendor workspace
        $wm->addPackage('modules', 'billing');
        $this->assertTrue($wm->hasPackage('billing'));
        $this->assertTrue($wm->hasPackage('acme/billing'));
        $this->assertSame(['billing'], $wm->getRawPackages('modules'));

        // 2. Re-add package using full canonical name with matching vendor -> updates without duplicate
        $wm->addPackage('modules', 'acme/billing', alias: 'BillingModule');
        $this->assertCount(1, $wm->getPackageNames('modules'));
        $fresh = WorkspaceManifest::open($this->manifestPath);
        $raw = $fresh->getRawPackages('modules');
        $this->assertCount(1, $raw);
        $this->assertIsArray($raw[0]);
        $this->assertSame('billing', $raw[0]['name']);
        $this->assertSame('BillingModule', $raw[0]['alias']);

        // 3. Add package with a DIFFERENT vendor to the same workspace -> preserves foreign vendor
        $wm->addPackage('modules', 'third-party/invoicing');
        $this->assertCount(2, $wm->getPackageNames('modules'));
        $this->assertTrue($wm->hasPackage('third-party/invoicing'));
        $this->assertFalse($wm->hasPackage('invoicing'));

        $pkgForeign = $wm->getPackage('third-party/invoicing');
        $this->assertNotNull($pkgForeign);
        $this->assertSame('third-party/invoicing', $pkgForeign->name);
        $this->assertSame('third-party/invoicing', $pkgForeign->canonicalName('acme'));

        $pkgDefault = $wm->getPackage('billing');
        $this->assertNotNull($pkgDefault);
        $this->assertSame('acme/billing', $pkgDefault->name);
        $this->assertSame('billing', $pkgDefault->shortName());
        $this->assertSame('acme/billing', $pkgDefault->canonicalName());

        // 4. Remove by canonical name
        $removed = $wm->removePackage('acme/billing', 'modules');
        $this->assertTrue($removed);
        $this->assertFalse($wm->hasPackage('billing'));
        $this->assertTrue($wm->hasPackage('third-party/invoicing'));

        // 5. Remove foreign package
        $removedForeign = $wm->removePackage('third-party/invoicing', 'modules');
        $this->assertTrue($removedForeign);
        $this->assertEmpty($wm->getRawPackages('modules'));
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
                new PackageDefinition(name: 'myvendor/invoicing', workspace: 'modules', alias: 'Invoicing'),
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

    public function test_workspace_manifest_mutate_dto_updates_state_and_cache(): void
    {
        $manifest = WorkspaceManifest::open($this->manifestPath)->init();
        $manifest->registerWorkspace('packages', 'acme');

        $returnedDto = $manifest->mutateDto(function (WorkspaceManifestDto $dto): WorkspaceManifestDto {
            return $dto->withPackage('packages', new PackageDefinition('acme/tester', 'packages'));
        });

        $this->assertTrue($returnedDto->hasPackage('acme/tester'));
        $this->assertSame($returnedDto, $manifest->toDto());
        $this->assertTrue($manifest->hasPackage('acme/tester'));

        $fresh = WorkspaceManifest::open($this->manifestPath);
        $this->assertTrue($fresh->hasPackage('acme/tester'));
    }

    public function test_workspace_manifest_caches_hydrated_dto_and_invalidates_on_mutation(): void
    {
        $manifest = WorkspaceManifest::open($this->manifestPath)->init();
        $manifest->registerWorkspace('packages', 'acme');

        // First hydration caches the DTO
        $dto1 = $manifest->toDto();
        $dto2 = $manifest->toDto();
        $this->assertSame($dto1, $dto2);

        // Mutation invalidates the cache
        $manifest->addPackage('packages', 'logger');
        $dto3 = $manifest->toDto();
        $this->assertNotSame($dto1, $dto3);
        $this->assertTrue($dto3->hasPackage('acme/logger'));

        // Clear cache explicitly
        $manifest->clearCache();
        $dto4 = $manifest->toDto();
        $this->assertNotSame($dto3, $dto4);

        // saveDto sets the cache
        $dto5 = $dto4->withWorkspace('tools');
        $manifest->saveDto($dto5);
        $this->assertSame($dto5, $manifest->toDto());
    }

    public function test_set_default_workspace_and_repository_url_template_persist_across_instances_and_mutations(): void
    {
        $manifest = WorkspaceManifest::open($this->manifestPath)->init();
        $manifest->registerWorkspace('packages');
        $manifest->registerWorkspace('modules', vendor: 'acme');

        // Set default and template
        $manifest->setDefaultWorkspace('modules');
        $manifest->setRepositoryUrlTemplate('https://gitlab.com/{package}.git');

        $this->assertSame('modules', $manifest->getDefaultWorkspace());
        $this->assertSame('https://gitlab.com/{package}.git', $manifest->getRepositoryUrlTemplate());
        $this->assertSame('modules', $manifest->toDto()->default);
        $this->assertSame('https://gitlab.com/{package}.git', $manifest->toDto()->repositoryUrlTemplate);

        // Open in a completely fresh instance from disk
        $freshManifest = WorkspaceManifest::open($this->manifestPath);
        $this->assertSame('modules', $freshManifest->getDefaultWorkspace());
        $this->assertSame('https://gitlab.com/{package}.git', $freshManifest->getRepositoryUrlTemplate());
        $this->assertSame('modules', $freshManifest->toDto()->default);
        $this->assertSame('https://gitlab.com/{package}.git', $freshManifest->toDto()->repositoryUrlTemplate);

        // Perform another mutation (addPackage) and verify default/template are NOT wiped out
        $freshManifest->addPackage('modules', 'billing');
        $this->assertTrue($freshManifest->hasPackage('billing'));

        $afterMutation = WorkspaceManifest::open($this->manifestPath);
        $this->assertSame('modules', $afterMutation->getDefaultWorkspace());
        $this->assertSame('https://gitlab.com/{package}.git', $afterMutation->getRepositoryUrlTemplate());
        $this->assertTrue($afterMutation->hasPackage('billing'));
    }

    public function test_register_workspace_already_exists_throws_exception(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('packages');

        $this->expectException(WorkspaceAlreadyExistsException::class);
        $wm->registerWorkspace('packages');
    }

    public function test_register_workspace_invalid_vendor_throws_exception(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        try {
            $wm->registerWorkspace('packages', vendor: 'Bad Vendor!');
            $this->fail('Expected InvalidVendorSlugException');
        } catch (InvalidVendorSlugException $e) {
            $this->assertSame('Bad Vendor!', $e->vendor);
            $this->assertSame('bad-vendor', $e->suggestion);
        }
    }

    public function test_unregister_workspace_not_found_throws_exception(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        $this->expectException(WorkspaceNotFoundException::class);
        $wm->unregisterWorkspace('non-existent');
    }

    public function test_add_package_to_non_existent_workspace_throws_exception(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        $this->expectException(WorkspaceNotFoundException::class);
        $wm->addPackage('non-existent', 'vendor/pkg');
    }

    public function test_add_package_invalid_name_throws_exception(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('packages');

        try {
            $wm->addPackage('packages', 'Acme Corp/Bad Package');
            $this->fail('Expected InvalidPackageNameException');
        } catch (InvalidPackageNameException $e) {
            $this->assertSame('Acme Corp/Bad Package', $e->packageName);
            $this->assertSame('acme-corp/bad-package', $e->suggestion);
        }
    }

    public function test_add_package_invalid_alias_throws_exception(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);
        $wm->registerWorkspace('packages');

        $this->expectException(InvalidPackageAliasException::class);
        $wm->addPackage('packages', 'acme/foo', alias: 'sub/alias');
    }

    public function test_set_workspace_vendor_not_found_and_invalid(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        try {
            $wm->setWorkspaceVendor('packages', 'acme');
            $this->fail('Expected WorkspaceNotFoundException');
        } catch (WorkspaceNotFoundException) {
            // expected
        }

        $wm->registerWorkspace('packages');

        try {
            $wm->setWorkspaceVendor('packages', 'Acme Corporation!');
            $this->fail('Expected InvalidVendorSlugException');
        } catch (InvalidVendorSlugException $e) {
            $this->assertSame('acme-corporation', $e->suggestion);
        }
    }

    public function test_set_repository_url_template_invalid_throws_exception(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        $this->expectException(InvalidRepositoryUrlTemplateException::class);
        $wm->setRepositoryUrlTemplate('https://github.com/missing-placeholder');
    }

    public function test_get_required_default_workspace(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath)->init();

        try {
            $wm->getRequiredDefaultWorkspace();
            $this->fail('Expected DefaultWorkspaceNotConfiguredException');
        } catch (DefaultWorkspaceNotConfiguredException) {
            // expected
        }

        $wm->registerWorkspace('packages', asDefault: true);
        $this->assertSame('packages', $wm->getRequiredDefaultWorkspace());
    }

    public function test_workspace_hooks(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath)->init();
        $wm->registerWorkspace('packages');

        $this->assertSame([], $wm->getWorkspaceHooks('packages'));

        $wm->setWorkspaceHook('packages', 'post_install', 'composer test');
        $this->assertSame(['post_install' => 'composer test'], $wm->getWorkspaceHooks('packages'));

        // Persistence test
        $fresh = WorkspaceManifest::open($this->manifestPath);
        $this->assertSame(['post_install' => 'composer test'], $fresh->getWorkspaceHooks('packages'));
        $this->assertSame(['post_install' => 'composer test'], $fresh->toDto()->getWorkspace('packages')?->hooks);

        // Remove hook
        $fresh->removeWorkspaceHook('packages', 'post_install');
        $this->assertSame([], $fresh->getWorkspaceHooks('packages'));

        // Calling on non-existent workspace throws WorkspaceNotFoundException
        $this->expectException(WorkspaceNotFoundException::class);
        $fresh->getWorkspaceHooks('non-existent');
    }
}
