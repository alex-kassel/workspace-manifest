<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Schemas;

use AlexKassel\ManifestEngine\Contracts\ManifestSchema;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;

class WorkspaceSchema implements ManifestSchema
{
    /**
     * Default state when a new workspace manifest is initialized.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'default' => null,
            'repository_url_template' => 'git@github.com:{package}.git',
            'workspaces' => [],
        ];
    }

    /**
     * Validate manifest data against workspace specification rules.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ManifestValidationException
     */
    public function validate(array $data, string $path): void
    {
        if (array_key_exists('workspaces', $data) && ! is_array($data['workspaces'])) {
            throw new ManifestValidationException(
                "Manifest [{$path}] section 'workspaces' must be an associative array.",
                ['workspaces' => 'Must be an associative array.']
            );
        }

        if (array_key_exists('default', $data) && $data['default'] !== null && ! is_string($data['default'])) {
            throw new ManifestValidationException(
                "Manifest [{$path}] attribute 'default' must be a string or null.",
                ['default' => 'Must be a string or null.']
            );
        }

        if (array_key_exists('repository_url_template', $data) && ! is_string($data['repository_url_template'])) {
            throw new ManifestValidationException(
                "Manifest [{$path}] attribute 'repository_url_template' must be a string.",
                ['repository_url_template' => 'Must be a string.']
            );
        }

        if (isset($data['workspaces']) && is_array($data['workspaces'])) {
            foreach ($data['workspaces'] as $workspace => $config) {
                if (! is_array($config)) {
                    throw new ManifestValidationException(
                        "Manifest [{$path}] workspace [{$workspace}] configuration must be an array.",
                        ["workspaces.{$workspace}" => 'Must be an array.']
                    );
                }

                if (array_key_exists('packages', $config) && ! is_array($config['packages'])) {
                    throw new ManifestValidationException(
                        "Manifest [{$path}] packages in workspace [{$workspace}] must be an array.",
                        ["workspaces.{$workspace}.packages" => 'Must be an array.']
                    );
                }

                if (isset($config['packages']) && is_array($config['packages'])) {
                    foreach ($config['packages'] as $idx => $pkg) {
                        if (is_string($pkg)) {
                            continue;
                        }

                        if (is_array($pkg)) {
                            if (! isset($pkg['name']) || ! is_string($pkg['name']) || trim($pkg['name']) === '') {
                                throw new ManifestValidationException(
                                    "Manifest [{$path}] package entry at index [{$idx}] in workspace [{$workspace}] must have a non-empty 'name' string.",
                                    ["workspaces.{$workspace}.packages.{$idx}.name" => 'Required non-empty string.']
                                );
                            }

                            continue;
                        }

                        throw new ManifestValidationException(
                            "Manifest [{$path}] package entry at index [{$idx}] in workspace [{$workspace}] must be a string or object with 'name'.",
                            ["workspaces.{$workspace}.packages.{$idx}" => 'Must be string or object.']
                        );
                    }
                }
            }
        }
    }
}
