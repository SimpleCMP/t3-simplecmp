<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;
use WapplerSystems\SimpleCmpTypo3\Service\ServiceCurator;

/**
 * Unit-tests the pure `buildServiceDefaults` transformation plus the
 * library-aware `buildDefaults` / `findLibraryMatch` (which iterate
 * the bundled `simplecmp/services-library` data and don't touch the
 * database). `findExistingServiceUid` is covered functionally because
 * it hits the database — see Tests/Functional/Service/ServiceCuratorTest.
 */
final class ServiceCuratorTest extends TestCase
{
    private function curator(): ServiceCurator
    {
        // The library-aware methods don't touch the repository or
        // connection pool, so unused mocks are fine here.
        return new ServiceCurator(
            $this->createMock(ServiceRepository::class),
            $this->createMock(ConnectionPool::class),
        );
    }

    #[Test]
    public function buildDefaultsUsesLibraryEntryWhenCookieMatches(): void
    {
        // Amplitude's bundled matcher is `/^amplitude_/` — should match.
        $defaults = $this->curator()->buildDefaults([
            'kind' => 'cookie',
            'identifier' => 'amplitude_session',
        ]);
        self::assertSame('amplitude', $defaults['service_id']);
        self::assertSame('Amplitude', $defaults['name']);
        self::assertSame('Amplitude, Inc.', $defaults['vendor']);
        self::assertSame('US', $defaults['vendor_country']);
        self::assertSame('["analytics"]', $defaults['purposes']);
        self::assertSame('https://amplitude.com/privacy', $defaults['privacy_policy_url']);
        self::assertArrayHasKey('description', $defaults);
        // matchers come from the library, not the bare detection
        self::assertStringContainsString('amplitude_', (string) $defaults['cookies']);
        self::assertStringContainsString('cdn.amplitude.com', (string) $defaults['origins']);
    }

    #[Test]
    public function buildDefaultsUsesLibraryEntryWhenOriginMatches(): void
    {
        // Bundled entries cover hosts like `cdn.amplitude.com`.
        $defaults = $this->curator()->buildDefaults([
            'kind' => 'script',
            'identifier' => 'https://cdn.amplitude.com/libs/amplitude-7.js',
            'origin' => 'cdn.amplitude.com',
        ]);
        self::assertSame('amplitude', $defaults['service_id']);
        self::assertSame('Amplitude', $defaults['name']);
        self::assertSame('["analytics"]', $defaults['purposes']);
    }

    #[Test]
    public function buildDefaultsFallsBackToBarePrefillWithoutLibraryMatch(): void
    {
        $defaults = $this->curator()->buildDefaults([
            'kind' => 'cookie',
            'identifier' => 'totally_made_up_cookie_xyz',
        ]);
        // No library entry covers this — bare path keeps raw identifier
        // as the name, slug, single-element cookies array, empty purposes.
        self::assertSame('totally-made-up-cookie-xyz', $defaults['service_id']);
        self::assertSame('totally_made_up_cookie_xyz', $defaults['name']);
        self::assertSame('[]', $defaults['purposes']);
        self::assertSame('["totally_made_up_cookie_xyz"]', $defaults['cookies']);
        self::assertArrayNotHasKey('vendor', $defaults);
    }

    #[Test]
    public function findLibraryMatchReturnsNullForUnrecognizedIdentifier(): void
    {
        self::assertNull($this->curator()->findLibraryMatch([
            'kind' => 'cookie',
            'identifier' => 'something_definitely_not_in_the_library',
        ]));
    }

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
