<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Schemas;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use Illuminate\JsonSchema\JsonSchema;

class WorkspaceSchema implements ManifestSchema
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
     * Full JSON Schema (Draft-07) representation for IDE autocomplete and static analysis.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $packageSchema = JsonSchema::anyOf([
            JsonSchema::string()->description('Package name in vendor/package format (e.g. "acme/billing").'),
            JsonSchema::object([
                'name' => JsonSchema::string()->description('Package name in vendor/package format.')->required(),
                'alias' => JsonSchema::string()->description('Directory alias for flat workspaces (e.g. "Billing").')->nullable(),
                'url' => JsonSchema::string()->description('Custom Git repository remote URL.')->nullable(),
                'skills' => JsonSchema::array()->description('Agent skills assigned to this package.'),
            ])->description('Structured package descriptor with metadata.'),
        ]);

        $packages = JsonSchema::array()
            ->items($packageSchema)
            ->description('List of registered packages in this workspace.')
            ->required();

        $workspaceItem = JsonSchema::object([
            'vendor' => JsonSchema::string()->description('Optional vendor namespace prefix for flat workspaces.')->nullable(),
            'packages' => $packages,
            'hooks' => JsonSchema::object()->description('Lifecycle workspace hooks and triggers.'),
        ])->toArray();
        $workspaceItem['additionalProperties'] = true;

        $root = JsonSchema::object([
            '$schema' => JsonSchema::string()->description('Path or URL to the JSON Schema specification.'),
            'default' => JsonSchema::string()->description('The default workspace directory (e.g. "packages").')->nullable(),
            'repository_url_template' => JsonSchema::string()->description('Git repository remote clone template (e.g. "git@github.com:{package}.git").'),
        ])->title('WorkspaceManifest')
            ->description('Multi-package workspace configuration for Laravel and PHP ecosystems.');

        $rootArray = $root->toArray();
        $rootArray['properties']['workspaces'] = [
            'type' => 'object',
            'description' => 'Map of registered workspace directories and their configurations.',
            'additionalProperties' => $workspaceItem,
        ];
        $rootArray['required'] = ['workspaces'];
        $rootArray['additionalProperties'] = true;

        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            ...$rootArray,
        ];
    }
}
