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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/workspace-manifest.php' => config_path('workspace-manifest.php'),
            ], 'workspace-manifest-config');

            $skillsSource = is_dir(__DIR__.'/../resources/boost/skills')
                ? __DIR__.'/../resources/boost/skills'
                : __DIR__.'/../resources/skills';

            if (is_dir($skillsSource)) {
                $targetPaths = (array) config('workspace-manifest.skills_path', ['.agents/skills']);
                $publishes = [];

                foreach ($targetPaths as $targetPath) {
                    $publishes[$skillsSource] = base_path($targetPath);
                }

                $this->publishes($publishes, 'workspace-manifest-skills');
            }
        }
    }
}
