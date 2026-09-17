<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Schemas;

use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use AlexKassel\WorkspaceManifest\Rules\ValidPackageEntryRule;

class WorkspaceSchema extends BaseSchema
{
    public const DEFAULT_SCHEMA_PATH = 'https://raw.githubusercontent.com/alex-kassel/workspace-manifest/main/resources/schema.json';

    public const DEFAULT_REPOSITORY_URL_TEMPLATE = 'git@github.com:{package}.git';

    /**
     * Default state when a new workspace manifest is initialized.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            '$schema' => self::DEFAULT_SCHEMA_PATH,
            'default' => null,
            'repository_url_template' => self::DEFAULT_REPOSITORY_URL_TEMPLATE,
            'workspaces' => [],
        ];
    }

    /**
     * Validation rules for workspace.json specification.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            '$schema' => ['sometimes', 'string'],
            'default' => ['sometimes', 'nullable', 'string'],
            'repository_url_template' => ['sometimes', 'string'],
            'workspaces' => ['present', 'array'],
            'workspaces.*' => ['array'],
            'workspaces.*.vendor' => ['sometimes', 'nullable', 'string'],
            'workspaces.*.packages' => ['present', 'array'],
            'workspaces.*.packages.*' => [new ValidPackageEntryRule],
            'workspaces.*.hooks' => ['sometimes', 'array'],
        ];
    }

    /**
     * Optional title for JSON Schema.
     */
    public function title(): ?string
    {
        return 'WorkspaceManifest';
    }

    /**
     * Optional description for JSON Schema.
     */
    public function description(): ?string
    {
        return 'Multi-package workspace configuration for Laravel and PHP ecosystems.';
    }

    /**
     * Human-readable descriptions for schema fields.
     *
     * @return array<string, string>
     */
    public function descriptions(): array
    {
        return [
            '$schema' => 'Path or URL to the JSON Schema specification.',
            'default' => 'The default workspace directory (e.g. "packages").',
            'repository_url_template' => 'Git repository remote clone template (e.g. "git@github.com:{package}.git").',
            'workspaces' => 'Map of registered workspace directories and their configurations.',
            'workspaces.*.vendor' => 'Optional vendor namespace prefix for flat workspaces.',
            'workspaces.*.packages' => 'List of registered packages in this workspace.',
            'workspaces.*.hooks' => 'Lifecycle workspace hooks and triggers.',
        ];
    }

    /**
     * Explicit type overrides for JSON Schema generation.
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        return [
            'workspaces' => 'object',
            'workspaces.*.hooks' => 'object',
        ];
    }
}
