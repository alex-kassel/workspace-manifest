<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Rules;

use AlexKassel\ManifestEngine\Contracts\HasJsonSchema;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidPackageEntryRule implements HasJsonSchema, ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                $fail("The {$attribute} package string cannot be empty.");
            }

            return;
        }

        if (is_array($value)) {
            if (! isset($value['name']) || ! is_string($value['name']) || trim($value['name']) === '') {
                $fail("The {$attribute} package object must have a non-empty 'name' string.");

                return;
            }

            if (array_key_exists('alias', $value) && $value['alias'] !== null && (! is_string($value['alias']) || trim($value['alias']) === '')) {
                $fail("The {$attribute} 'alias' must be a non-empty string or null.");
            }

            if (array_key_exists('url', $value) && $value['url'] !== null && (! is_string($value['url']) || trim($value['url']) === '')) {
                $fail("The {$attribute} 'url' must be a non-empty string or null.");
            }

            if (array_key_exists('skills', $value) && ! is_array($value['skills'])) {
                $fail("The {$attribute} 'skills' must be an array of string slugs.");
            }

            return;
        }

        $fail("The {$attribute} must be a package name string (e.g. 'vendor/pkg') or a package descriptor object with 'name'.");
    }

    /**
     * Get the JSON Schema (Draft-07 fragment) for this validation rule.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return [
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
                            'items' => [
                                'type' => 'string',
                            ],
                            'description' => 'Agent skills assigned to this package.',
                        ],
                    ],
                    'required' => ['name'],
                    'additionalProperties' => true,
                ],
            ],
        ];
    }
}
