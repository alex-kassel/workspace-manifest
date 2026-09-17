<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest;

use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\WorkspaceManifest\Console\Commands\WorkspaceInstallCommand;
use AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema;
use AlexKassel\WorkspaceManifest\Services\WorkspaceRunnerInstaller;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class WorkspaceManifestServiceProvider extends ServiceProvider
{
    public const CONFIG_KEY = 'workspace-manifest';

    public const CONFIG_PATH_KEY = 'workspace-manifest.path';

    public const REGISTRATION_NAME = 'workspace';

    public const REGISTRATION_DESCRIPTION = 'Multi-package workspace monorepo configuration';

    public const METADATA_RUNNER_PATH_KEY = 'runnerPath';

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/workspace-manifest.php',
            self::CONFIG_KEY
        );

        $this->app->singleton(WorkspaceManifest::class, function () {
            return WorkspaceManifest::open();
        });

        $this->app->singleton(WorkspaceRunnerInstaller::class, function ($app) {
            return new WorkspaceRunnerInstaller(
                files: $app->make(Filesystem::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/workspace-manifest.php' => config_path('workspace-manifest.php'),
            ], 'workspace-manifest-config');

            $this->commands([
                WorkspaceInstallCommand::class,
            ]);
        }

        if ($this->app->bound(ManifestRegistry::class)) {
            /** @var ManifestRegistry $registry */
            $registry = $this->app->make(ManifestRegistry::class);

            $configuredPath = config(self::CONFIG_PATH_KEY);
            $filename = is_string($configuredPath) && trim($configuredPath) !== ''
                ? trim($configuredPath)
                : WorkspaceManifest::DEFAULT_FILENAME;

            $registry->register(
                name: self::REGISTRATION_NAME,
                filename: $filename,
                schema: WorkspaceSchema::class,
                description: self::REGISTRATION_DESCRIPTION,
                metadata: [
                    self::METADATA_RUNNER_PATH_KEY => __DIR__.'/../stubs/workspace.stub',
                ],
            );
        }
    }
}
