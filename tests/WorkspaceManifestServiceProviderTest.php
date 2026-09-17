<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;
use AlexKassel\WorkspaceManifest\WorkspaceManifestServiceProvider;
use Illuminate\Foundation\Application;

class WorkspaceManifestServiceProviderTest extends TestCase
{
    public function test_it_registers_singletons_in_container(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);

        $this->assertTrue($app->bound(WorkspaceManifest::class));

        $instance = $app->make(WorkspaceManifest::class);
        $this->assertInstanceOf(WorkspaceManifest::class, $instance);
        $this->assertSame(
            base_path(WorkspaceManifest::DEFAULT_FILENAME),
            $instance->getPath()
        );
    }

    public function test_it_registers_in_manifest_registry_when_registry_is_bound(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);

        $registry = new ManifestRegistry;
        $app->instance(ManifestRegistry::class, $registry);

        $provider = new WorkspaceManifestServiceProvider($app);
        $provider->boot();

        $this->assertTrue($registry->has(WorkspaceManifestServiceProvider::REGISTRATION_NAME));
        $definition = $registry->get(WorkspaceManifestServiceProvider::REGISTRATION_NAME);
        $this->assertNotNull($definition);
        $this->assertSame(WorkspaceManifest::DEFAULT_FILENAME, $definition->filename);
        $this->assertSame(WorkspaceSchema::class, $definition->schema);
    }
}
