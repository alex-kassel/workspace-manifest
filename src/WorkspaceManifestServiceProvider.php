<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest;

use Illuminate\Support\ServiceProvider;

class WorkspaceManifestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/workspace-manifest.php',
            'workspace-manifest'
        );

        $this->app->singleton(WorkspaceManifest::class, function ($app) {
            $path = (string) config('workspace-manifest.path', function_exists('base_path') ? base_path('workspace.json') : 'workspace.json');

            return WorkspaceManifest::open($path);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/workspace-manifest.php' => config_path('workspace-manifest.php'),
            ], 'workspace-manifest-config');
        }
    }
}
