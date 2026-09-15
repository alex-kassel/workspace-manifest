<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\ManifestEngine\ManifestEngineServiceProvider;
use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\WorkspaceManifest\Services\WorkspaceRunnerInstaller;
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
        $this->tempDir = sys_get_temp_dir().'/ws_installer_test_'.uniqid();
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
        $runner = dirname(__DIR__).'/bin/workspace';

        $result = Process::run(['php', $runner, 'help']);
        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('Workspace Standalone Runner CLI', $result->output());
        $this->assertStringContainsString('restore', $result->output());
        $this->assertStringContainsString('status', $result->output());
    }

    public function test_runner_status_command(): void
    {
        $runner = dirname(__DIR__).'/bin/workspace';

        $result = Process::path(base_path())->run(['php', $runner, 'status']);
        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('Workspace Packages Status', $result->output());
    }
}
