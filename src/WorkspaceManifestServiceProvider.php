<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest;

use AlexKassel\ManifestEngine\DTOs\ManifestDefinition;
use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema;
use Illuminate\Support\ServiceProvider;

class WorkspaceManifestServiceProvider extends ServiceProvider
{
    public const CONFIG_KEY = 'workspace-manifest';

    public const CONFIG_PATH_KEY = 'workspace-manifest.path';

    public const REGISTRATION_NAME = 'workspace';

    public const REGISTRATION_DESCRIPTION = 'Multi-package workspace monorepo configuration';

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/workspace-manifest.php',
            self::CONFIG_KEY
        );

        $this->app->singleton(WorkspaceManifest::class, function () {
            return WorkspaceManifest::open();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/workspace-manifest.php' => config_path('workspace-manifest.php'),
            ], 'workspace-manifest-config');
        }

        if ($this->app->bound(ManifestRegistry::class)) {
            /** @var ManifestRegistry $registry */
            $registry = $this->app->make(ManifestRegistry::class);

            $configuredPath = config(self::CONFIG_PATH_KEY);
            $targetPath = is_string($configuredPath) && trim($configuredPath) !== ''
                ? trim($configuredPath)
                : WorkspaceManifest::DEFAULT_FILENAME;

            $registry->register(new ManifestDefinition(
                name: self::REGISTRATION_NAME,
                path: base_path($targetPath),
                schema: new WorkspaceSchema,
                description: self::REGISTRATION_DESCRIPTION,
            ));
        }
    }
}
