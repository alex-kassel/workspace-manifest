<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Schemas;

use AlexKassel\ManifestEngine\Schemas\BaseSchema;
use AlexKassel\WorkspaceManifest\Rules\ValidPackageEntryRule;

class WorkspaceSchema extends BaseSchema
{
    /**
     * Default state when a new workspace manifest is initialized.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            '$schema' => './packages/alex-kassel/workspace-manifest/resources/schema.json',
            'default' => null,
            'repository_url_template' => 'git@github.com:{package}.git',
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
     * Full JSON Schema (Draft-07) definition for IDE autocomplete and linting.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'WorkspaceManifest',
            'description' => 'Multi-package workspace configuration for Laravel and PHP ecosystems.',
            'type' => 'object',
            'properties' => [
                '$schema' => [
                    'type' => 'string',
                    'description' => 'Path or URL to the JSON Schema specification.',
                ],
                'default' => [
                    'type' => ['string', 'null'],
                    'description' => 'The default workspace directory (e.g. "packages").',
                ],
                'repository_url_template' => [
                    'type' => 'string',
                    'description' => 'Git repository remote clone template (e.g. "git@github.com:{package}.git").',
                ],
                'workspaces' => [
                    'type' => 'object',
                    'description' => 'Map of registered workspace directories and their configurations.',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'vendor' => [
                                'type' => ['string', 'null'],
                                'description' => 'Optional vendor namespace prefix for flat workspaces.',
                            ],
                            'packages' => [
                                'type' => 'array',
                                'description' => 'List of registered packages in this workspace.',
                                'items' => [
                                    'oneOf' => [
                                        [
                                            'type' => 'string',
                                            'description' => 'Package name in vendor/package format (e.g. "acme/billing").',
                                        ],
                                        [
                                            'type' => 'object',
                                            'description' => 'Structured package descriptor with metadata.',
                                            'properties' => [
                                                'name' => [
                                                    'type' => 'string',
                                                    'description' => 'Package name in vendor/package format.',
                                                ],
                                                'alias' => [
                                                    'type' => 'string',
                                                    'description' => 'Directory alias for flat workspaces (e.g. "Billing").',
                                                ],
                                                'url' => [
                                                    'type' => 'string',
                                                    'description' => 'Custom Git repository remote URL.',
                                                ],
                                                'skills' => [
                                                    'type' => 'array',
                                                    'items' => ['type' => 'string'],
                                                    'description' => 'Agent skills assigned to this package.',
                                                ],
                                            ],
                                            'required' => ['name'],
                                            'additionalProperties' => true,
                                        ],
                                    ],
                                ],
                            ],
                            'hooks' => [
                                'type' => 'object',
                                'description' => 'Lifecycle workspace hooks and triggers.',
                            ],
                        ],
                        'required' => ['packages'],
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'required' => ['workspaces'],
            'additionalProperties' => true,
        ];
    }
}
