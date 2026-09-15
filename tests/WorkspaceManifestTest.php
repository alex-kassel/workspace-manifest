<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;
use Illuminate\Support\Facades\File;

class WorkspaceManifestTest extends TestCase
{
    protected string $testDir;

    protected string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir().'/workspace_manifest_test_'.uniqid();
        File::makeDirectory($this->testDir, 0755, true);
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
        $this->assertSame('./packages/alex-kassel/workspace-manifest/resources/schema.json', $wm->manifest()->get('$schema'));
    }

    public function test_register_and_unregister_workspace(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        $wm->registerWorkspace('packages', vendor: 'alex-kassel', asDefault: true);
        $wm->registerWorkspace('modules', vendor: 'alex-kassel');

        $this->assertTrue($wm->hasWorkspace('packages'));
        $this->assertTrue($wm->hasWorkspace('modules'));
        $this->assertSame(['packages', 'modules'], $wm->getWorkspaceNames());
        $this->assertSame('packages', $wm->getDefaultWorkspace());
        $this->assertSame('alex-kassel', $wm->getWorkspaceVendor('packages'));

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

        $wm->addPackage('packages', 'vendor/pkg-simple');
        $this->assertTrue($wm->hasPackage('vendor/pkg-simple'));
        $this->assertSame('packages', $wm->findPackageWorkspace('vendor/pkg-simple'));

        $pkg = $wm->getPackage('vendor/pkg-simple');
        $this->assertNotNull($pkg);
        $this->assertSame('vendor/pkg-simple', $pkg['name']);
        $this->assertNull($pkg['alias']);

        // Add structured package with alias and skills
        $wm->addPackage(
            workspace: 'packages',
            packageName: 'vendor/pkg-rich',
            alias: 'RichPackage',
            url: 'git@custom.repo/pkg-rich.git',
            skills: ['skill-1', 'skill-2']
        );

        $this->assertTrue($wm->hasPackage('vendor/pkg-rich'));
        $rich = $wm->getPackage('vendor/pkg-rich');
        $this->assertNotNull($rich);
        $this->assertSame('RichPackage', $rich['alias']);
        $this->assertSame('git@custom.repo/pkg-rich.git', $rich['url']);
        $this->assertSame(['skill-1', 'skill-2'], $rich['skills']);

        // Deduplication test: re-adding does not create duplicate entries
        $wm->addPackage('packages', 'vendor/pkg-simple');
        $this->assertCount(2, $wm->getPackageNames('packages'));
        $this->assertCount(2, $wm->getPackageNames());

        $raw = $wm->getRawPackages('packages');
        $this->assertCount(2, $raw);
    }

    public function test_remove_package_with_empty_workspace_prune(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

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

    public function test_schema_validation_rejects_invalid_structure(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        $this->expectException(ManifestValidationException::class);
        $wm->manifest()->save([
            'workspaces' => 'invalid-not-an-array',
        ]);
    }

    public function test_schema_validation_rejects_invalid_package_entry(): void
    {
        $wm = WorkspaceManifest::open($this->manifestPath);

        $this->expectException(ManifestValidationException::class);
        $wm->manifest()->save([
            'workspaces' => [
                'packages' => [
                    'packages' => [
                        ['invalid' => 'no-name-key'],
                    ],
                ],
            ],
        ]);
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
}
