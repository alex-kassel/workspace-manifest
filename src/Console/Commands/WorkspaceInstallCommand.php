<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Console\Commands;

use AlexKassel\WorkspaceManifest\Services\WorkspaceRunnerInstaller;
use Illuminate\Console\Command;

class WorkspaceInstallCommand extends Command
{
    public const OPTION_FORCE = 'force';

    public const ICON_CREATED = '<info>✔</info>';

    public const ICON_SKIPPED = '<comment>⏭</comment>';

    public const ICON_DEFAULT = '•';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:install
        {--force : Force overwrite existing manifest and runner files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize workspace manifest and publish standalone executable runner';

    public function __construct(
        protected readonly WorkspaceRunnerInstaller $installer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = (bool) $this->option(self::OPTION_FORCE);
        $rootPath = function_exists('base_path') ? base_path() : (string) getcwd();

        $this->info('Installing workspace manifest and standalone runner...');
        $steps = $this->installer->install($rootPath, $force);

        foreach ($steps as $step) {
            $icon = match ($step['status']) {
                WorkspaceRunnerInstaller::STATUS_CREATED => self::ICON_CREATED,
                WorkspaceRunnerInstaller::STATUS_SKIPPED => self::ICON_SKIPPED,
                default => self::ICON_DEFAULT,
            };
            $this->line("  {$icon} {$step['message']}");
        }

        $this->newLine();
        $this->info('Workspace installation completed successfully.');

        return self::SUCCESS;
    }
}
