<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\ManifestEngine\ManifestEngineServiceProvider;
use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\WorkspaceManifest\Services\WorkspaceRunnerInstaller;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;
use AlexKassel\WorkspaceManifest\WorkspaceManifestServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

class WorkspaceRunnerTest extends TestCase
{
    protected Filesystem $files;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = storage_path('framework/testing/ws_installer_'.bin2hex(random_bytes(6)));
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ManifestEngineServiceProvider::class,
            WorkspaceManifestServiceProvider::class,
        ];
    }

    public function test_workspace_manifest_is_registered_in_manifest_registry(): void
    {
        /** @var ManifestRegistry $registry */
        $registry = app(ManifestRegistry::class);

        $this->assertTrue($registry->has('workspace'));
        $def = $registry->get('workspace');
        $this->assertNotNull($def);
        $this->assertSame('workspace.json', $def->filename);
        $this->assertArrayHasKey('runnerPath', $def->metadata);
        $this->assertFileExists($def->metadata['runnerPath']);
    }

    public function test_workspace_installer_scaffolds_manifest_and_runner(): void
    {
        /** @var WorkspaceRunnerInstaller $installer */
        $installer = app(WorkspaceRunnerInstaller::class);

        $steps = $installer->install($this->tempDir);
        $this->assertCount(2, $steps);
        $this->assertSame('created', $steps[0]['status']);
        $this->assertSame('created', $steps[1]['status']);

        $this->assertTrue($this->files->exists("{$this->tempDir}/workspace.json"));
        $this->assertTrue($this->files->exists("{$this->tempDir}/workspace"));
    }

    public function test_runner_help_command(): void
    {
        /** @var WorkspaceRunnerInstaller $installer */
        $installer = app(WorkspaceRunnerInstaller::class);
        $installer->install($this->tempDir);
        $runner = "{$this->tempDir}/workspace";

        $result = Process::run(['php', $runner, 'help']);
        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('Workspace Standalone Runner CLI', $result->output());
        $this->assertStringContainsString('restore', $result->output());
        $this->assertStringContainsString('status', $result->output());
    }

    public function test_runner_status_command(): void
    {
        /** @var WorkspaceRunnerInstaller $installer */
        $installer = app(WorkspaceRunnerInstaller::class);
        $installer->install($this->tempDir);
        $runner = "{$this->tempDir}/workspace";

        $result = Process::path($this->tempDir)->run(['php', $runner, 'status']);
        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('Workspace Packages Status', $result->output());
    }

    public function test_workspace_manifest_singleton_resolves_from_container(): void
    {
        $instance = app(WorkspaceManifest::class);

        $this->assertInstanceOf(WorkspaceManifest::class, $instance);
        $this->assertSame(base_path('workspace.json'), $instance->manifest()->path);
    }

    public function test_service_provider_registers_custom_relative_path(): void
    {
        config(['workspace-manifest.path' => 'custom/config/workspace.json']);

        $provider = new WorkspaceManifestServiceProvider(app());
        $provider->register();
        $provider->boot();

        /** @var ManifestRegistry $registry */
        $registry = app(ManifestRegistry::class);
        $def = $registry->get('workspace');

        $this->assertNotNull($def);
        $this->assertSame('custom/config/workspace.json', $def->filename);

        /** @var WorkspaceManifest $instance */
        $instance = app(WorkspaceManifest::class);
        $this->assertSame(base_path('custom/config/workspace.json'), $instance->manifest()->path);
    }

    public function test_service_provider_normalizes_absolute_path_within_base_path(): void
    {
        config(['workspace-manifest.path' => base_path('sub/nested/workspace.json')]);

        $provider = new WorkspaceManifestServiceProvider(app());
        $provider->register();
        $provider->boot();

        /** @var ManifestRegistry $registry */
        $registry = app(ManifestRegistry::class);
        $def = $registry->get('workspace');

        $this->assertNotNull($def);
        $this->assertSame('sub/nested/workspace.json', $def->filename);

        /** @var WorkspaceManifest $instance */
        $instance = app(WorkspaceManifest::class);
        $this->assertSame(base_path('sub/nested/workspace.json'), $instance->manifest()->path);
    }
}
