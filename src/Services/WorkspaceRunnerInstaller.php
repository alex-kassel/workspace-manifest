<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Services;

use AlexKassel\StubEngine\Services\StubEngine;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;
use Illuminate\Filesystem\Filesystem;

class WorkspaceRunnerInstaller
{
    public const DEFAULT_RUNNER_NAME = 'workspace';

    public const DEFAULT_STUBS_DIR = 'stubs';

    public const DEFAULT_STUB_FILENAME = 'workspace.stub';

    public const TYPE_MANIFEST = 'manifest';

    public const TYPE_RUNNER = 'runner';

    public const STATUS_CREATED = 'created';

    public const STATUS_SKIPPED = 'skipped';

    public const TOKEN_MANIFEST_PATH = '{{ manifestPath }}';

    public const TOKEN_RUNNER_NAME = '{{ runnerName }}';

    public function __construct(
        protected Filesystem $files = new Filesystem,
        protected StubEngine $stubEngine = new StubEngine,
    ) {}

    /**
     * Install workspace manifest and publish standalone runner.
     *
     * @return array<int, array{type: string, status: string, message: string}>
     */
    public function install(string $rootPath, bool $force = false): array
    {
        $steps = [];
        $configPath = config('workspace-manifest.path');
        $manifestFilename = is_string($configPath) && trim($configPath) !== ''
            ? trim($configPath)
            : WorkspaceManifest::DEFAULT_FILENAME;
        $manifestPath = $rootPath.DIRECTORY_SEPARATOR.$manifestFilename;

        // 1. Initialize workspace manifest if missing
        if ($this->files->exists($manifestPath) && ! $force) {
            $steps[] = [
                'type' => self::TYPE_MANIFEST,
                'status' => self::STATUS_SKIPPED,
                'message' => "Workspace manifest [{$manifestFilename}] already exists.",
            ];
        } else {
            $manifest = WorkspaceManifest::open($manifestPath);
            $manifest->init();

            $steps[] = [
                'type' => self::TYPE_MANIFEST,
                'status' => self::STATUS_CREATED,
                'message' => "Initialized workspace manifest [{$manifestFilename}].",
            ];
        }

        // 2. Publish standalone executable runner
        $sourceStub = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.self::DEFAULT_STUB_FILENAME;
        $targetRunner = $rootPath.DIRECTORY_SEPARATOR.self::DEFAULT_RUNNER_NAME;
        $overrideStub = $rootPath.DIRECTORY_SEPARATOR.self::DEFAULT_STUBS_DIR.DIRECTORY_SEPARATOR.self::DEFAULT_STUB_FILENAME;

        if ($this->files->exists($sourceStub)) {
            $created = $this->stubEngine->scaffoldFile(
                sourceFile: $sourceStub,
                targetFile: $targetRunner,
                tokens: [
                    self::TOKEN_MANIFEST_PATH => $manifestFilename,
                    self::TOKEN_RUNNER_NAME => self::DEFAULT_RUNNER_NAME,
                ],
                overrideFile: $overrideStub,
                force: $force,
            );

            if ($created) {
                $this->files->chmod($targetRunner, 0755);

                $steps[] = [
                    'type' => self::TYPE_RUNNER,
                    'status' => self::STATUS_CREATED,
                    'message' => 'Published standalone workspace runner to [./'.self::DEFAULT_RUNNER_NAME.'].',
                ];
            } else {
                $steps[] = [
                    'type' => self::TYPE_RUNNER,
                    'status' => self::STATUS_SKIPPED,
                    'message' => 'Standalone workspace runner [./'.self::DEFAULT_RUNNER_NAME.'] already exists.',
                ];
            }
        }

        return $steps;
    }
}
