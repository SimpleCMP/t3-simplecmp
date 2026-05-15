<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\SimpleCmpTypo3\Service\ServiceCurator;

/**
 * Unit-tests the pure `buildServiceDefaults` transformation.
 * `findExistingServiceUid` is covered functionally because it
 * hits the database — see Tests/Functional/Service/ServiceCuratorTest.
 */
final class ServiceCuratorTest extends TestCase
{
    #[Test]
    public function cookieDetectionEmitsCookieMatcher(): void
    {
        $defaults = ServiceCurator::buildServiceDefaults([
            'kind' => 'cookie',
            'identifier' => '_ga',
        ]);
        // Slug strips the leading underscore (non-alnum → '-' → trimmed).
        self::assertSame('ga', $defaults['service_id']);
        // `name` keeps the raw identifier; admin edits.
        self::assertSame('_ga', $defaults['name']);
        self::assertSame('[]', $defaults['purposes']);
        self::assertSame('["_ga"]', $defaults['cookies']);
        self::assertArrayNotHasKey('origins', $defaults);
    }

    #[Test]
    public function nonCookieDetectionEmitsOriginMatcher(): void
    {
        $defaults = ServiceCurator::buildServiceDefaults([
            'kind' => 'script',
            'identifier' => 'https://gtag.googletagmanager.com/gtag/js',
            'origin' => 'googletagmanager.com',
        ]);
        self::assertSame('["googletagmanager.com"]', $defaults['origins']);
        self::assertArrayNotHasKey('cookies', $defaults);
    }

    #[Test]
    public function nonCookieDetectionWithoutOriginSkipsBothMatchers(): void
    {
        $defaults = ServiceCurator::buildServiceDefaults([
            'kind' => 'script',
            'identifier' => 'https://example.com/anonymous.js',
        ]);
        self::assertArrayNotHasKey('cookies', $defaults);
        self::assertArrayNotHasKey('origins', $defaults);
    }

    #[Test]
    public function slugLowercasesAndKebabsNonAlphanumerics(): void
    {
        $defaults = ServiceCurator::buildServiceDefaults([
            'kind' => 'cookie',
            'identifier' => '_GA_ABC.123',
        ]);
        self::assertSame('ga-abc-123', $defaults['service_id']);
        // `name` keeps the original verbatim
        self::assertSame('_GA_ABC.123', $defaults['name']);
    }

    #[Test]
    public function slugFallsBackToUnknownForUnusableIdentifier(): void
    {
        $defaults = ServiceCurator::buildServiceDefaults([
            'kind' => 'cookie',
            'identifier' => '___',
        ]);
        self::assertSame('unknown', $defaults['service_id']);
    }

    #[Test]
    public function emptyIdentifierStillReturnsValidDefaults(): void
    {
        $defaults = ServiceCurator::buildServiceDefaults([
            'kind' => 'cookie',
            'identifier' => '',
        ]);
        self::assertSame('unknown', $defaults['service_id']);
        self::assertSame('', $defaults['name']);
        // empty identifier still produces a cookies array of [''] — admin will edit.
        self::assertSame('[""]', $defaults['cookies']);
    }

    #[Test]
    public function missingFieldsAreTreatedAsEmpty(): void
    {
        $defaults = ServiceCurator::buildServiceDefaults([]);
        self::assertSame('unknown', $defaults['service_id']);
        self::assertSame('', $defaults['name']);
        self::assertSame('[]', $defaults['purposes']);
        self::assertArrayNotHasKey('cookies', $defaults);
        self::assertArrayNotHasKey('origins', $defaults);
    }
}
