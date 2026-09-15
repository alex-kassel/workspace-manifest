<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\ManifestEngine\ManifestEngineServiceProvider;
use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\WorkspaceManifest\WorkspaceManifestServiceProvider;
use Illuminate\Support\Facades\Process;

class WorkspaceRunnerTest extends TestCase
{
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
        $this->assertNotNull($def->runnerPath);
        $this->assertFileExists($def->runnerPath);
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
