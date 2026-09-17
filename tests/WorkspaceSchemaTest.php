<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\WorkspaceManifest\Schemas\WorkspaceSchema;

class WorkspaceSchemaTest extends TestCase
{
    public function test_it_generates_valid_draft_07_json_schema_dynamically(): void
    {
        $schema = new WorkspaceSchema;
        $jsonSchema = $schema->jsonSchema();

        $this->assertNotEmpty($jsonSchema);
        $this->assertSame('http://json-schema.org/draft-07/schema#', $jsonSchema['$schema']);
        $this->assertSame('WorkspaceManifest', $jsonSchema['title']);
        $this->assertSame('object', $jsonSchema['type']);
        $this->assertContains('workspaces', $jsonSchema['required']);

        // Properties
        $properties = $jsonSchema['properties'];
        $this->assertArrayHasKey('default', $properties);
        $this->assertSame(['string', 'null'], $properties['default']['type']);

        $this->assertArrayHasKey('workspaces', $properties);
        $this->assertSame('object', $properties['workspaces']['type']);
        $this->assertArrayHasKey('additionalProperties', $properties['workspaces']);

        $additionalProps = $properties['workspaces']['additionalProperties'];
        $this->assertSame('object', $additionalProps['type']);
        $this->assertContains('packages', $additionalProps['required']);

        $packageItems = $additionalProps['properties']['packages']['items'];
        $this->assertArrayHasKey('anyOf', $packageItems);
        $this->assertCount(2, $packageItems['anyOf']);
        $this->assertSame('string', $packageItems['anyOf'][0]['type']);
        $this->assertSame('object', $packageItems['anyOf'][1]['type']);
    }
}
