<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceManifest\Tests;

use AlexKassel\WorkspaceManifest\Validation\WorkspaceValidator;

class WorkspaceValidatorTest extends TestCase
{
    public function test_validate_package_name_canonical_valid(): void
    {
        $result = WorkspaceValidator::validatePackageName('acme/package-name');
        $this->assertTrue($result->isValid());
        $this->assertNull($result->errorMessage());
        $this->assertNull($result->suggestion());
        $this->assertSame('acme', $result->vendor);
        $this->assertSame('package-name', $result->package);
        $this->assertSame('acme/package-name', $result->canonicalName);
        $this->assertSame('package-name', $result->shortName);
        $this->assertSame('acme/package-name', $result->storedName);
    }

    public function test_validate_package_name_canonical_with_workspace_vendor(): void
    {
        // When input vendor matches workspace vendor, storedName is shortName
        $result = WorkspaceValidator::validatePackageName('acme/billing', 'acme');
        $this->assertTrue($result->isValid());
        $this->assertSame('acme', $result->vendor);
        $this->assertSame('billing', $result->package);
        $this->assertSame('acme/billing', $result->canonicalName);
        $this->assertSame('billing', $result->storedName);

        // When input vendor differs from workspace vendor, storedName remains canonical
        $result2 = WorkspaceValidator::validatePackageName('other-vendor/billing', 'acme');
        $this->assertTrue($result2->isValid());
        $this->assertSame('other-vendor', $result2->vendor);
        $this->assertSame('other-vendor/billing', $result2->canonicalName);
        $this->assertSame('other-vendor/billing', $result2->storedName);
    }

    public function test_validate_package_name_short_with_workspace_vendor(): void
    {
        $result = WorkspaceValidator::validatePackageName('billing-core', 'acme');
        $this->assertTrue($result->isValid());
        $this->assertSame('acme', $result->vendor);
        $this->assertSame('billing-core', $result->package);
        $this->assertSame('acme/billing-core', $result->canonicalName);
        $this->assertSame('billing-core', $result->shortName);
        $this->assertSame('billing-core', $result->storedName);
    }

    public function test_validate_package_name_short_without_workspace_vendor(): void
    {
        $result = WorkspaceValidator::validatePackageName('billing-core', null);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('requires a vendor prefix', (string) $result->errorMessage());
        $this->assertSame('my-vendor/billing-core', $result->suggestion());
    }

    public function test_validate_package_name_empty(): void
    {
        $result = WorkspaceValidator::validatePackageName('   ');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot be empty', (string) $result->errorMessage());
    }

    public function test_validate_package_name_multiple_slashes(): void
    {
        $result = WorkspaceValidator::validatePackageName('vendor/sub/package');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('can only contain at most one slash', (string) $result->errorMessage());
    }

    public function test_validate_package_name_invalid_chars_generates_suggestion(): void
    {
        $result = WorkspaceValidator::validatePackageName('Acme Corp/My Package');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid package name', (string) $result->errorMessage());
        $this->assertSame('acme-corp/my-package', $result->suggestion());
    }

    public function test_validate_vendor_slug_valid(): void
    {
        $result = WorkspaceValidator::validateVendorSlug('acme-corp');
        $this->assertTrue($result->isValid());
        $this->assertSame('acme-corp', $result->normalized());
    }

    public function test_validate_vendor_slug_nullable(): void
    {
        $resultNullable = WorkspaceValidator::validateVendorSlug(null, nullable: true);
        $this->assertTrue($resultNullable->isValid());
        $this->assertNull($resultNullable->normalized());

        $resultNotNullable = WorkspaceValidator::validateVendorSlug(null, nullable: false);
        $this->assertFalse($resultNotNullable->isValid());
        $this->assertStringContainsString('cannot be empty', (string) $resultNotNullable->errorMessage());
    }

    public function test_validate_vendor_slug_invalid_generates_suggestion(): void
    {
        $result = WorkspaceValidator::validateVendorSlug('Acme Corp!');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid vendor slug', (string) $result->errorMessage());
        $this->assertSame('acme-corp', $result->suggestion());
    }

    public function test_validate_workspace_path(): void
    {
        $valid = WorkspaceValidator::validateWorkspacePath('packages/core/../modules');
        $this->assertTrue($valid->isValid());
        $this->assertSame('packages/modules', $valid->normalized());

        $absolute = WorkspaceValidator::validateWorkspacePath('/var/www/packages');
        $this->assertFalse($absolute->isValid());
        $this->assertStringContainsString('Absolute paths are not allowed', (string) $absolute->errorMessage());

        $traversal = WorkspaceValidator::validateWorkspacePath('../../outside');
        $this->assertFalse($traversal->isValid());
        $this->assertStringContainsString('Path traversal', (string) $traversal->errorMessage());
    }

    public function test_validate_package_alias(): void
    {
        $this->assertTrue(WorkspaceValidator::validatePackageAlias(null)->isValid());
        $this->assertTrue(WorkspaceValidator::validatePackageAlias('')->isValid());
        $this->assertTrue(WorkspaceValidator::validatePackageAlias('BillingService')->isValid());

        $slash = WorkspaceValidator::validatePackageAlias('foo/bar');
        $this->assertFalse($slash->isValid());
        $this->assertStringContainsString('cannot contain slashes', (string) $slash->errorMessage());

        $traversal = WorkspaceValidator::validatePackageAlias('..');
        $this->assertFalse($traversal->isValid());
    }

    public function test_validate_repository_url_template(): void
    {
        $valid = WorkspaceValidator::validateRepositoryUrlTemplate('git@gitlab.com:{package}.git');
        $this->assertTrue($valid->isValid());
        $this->assertSame('git@gitlab.com:{package}.git', $valid->normalized());

        $missingPlaceholder = WorkspaceValidator::validateRepositoryUrlTemplate('git@gitlab.com:alex/repo.git');
        $this->assertFalse($missingPlaceholder->isValid());
        $this->assertStringContainsString("must contain the '{package}' placeholder", (string) $missingPlaceholder->errorMessage());

        $empty = WorkspaceValidator::validateRepositoryUrlTemplate('');
        $this->assertFalse($empty->isValid());
        $this->assertStringContainsString('cannot be empty', (string) $empty->errorMessage());
    }
}
