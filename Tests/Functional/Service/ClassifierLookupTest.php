<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\ClassifierLookup;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamClient;

/**
 * Lookups against an empty registry MUST still return library coverage.
 * That's the whole point of consulting both sources — admin can curate
 * an empty registry while the classifier silently knows about every
 * common third-party cookie via the bundled JSON library.
 */
final class ClassifierLookupTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['simplecmp/t3-simplecmp'];

    private ClassifierLookup $classifier;
    private ServiceRepository $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = $this->get(ClassifierLookup::class);
        $this->registry = $this->get(ServiceRepository::class);
    }

    #[Test]
    public function libraryCoversCookiesEvenWithEmptyRegistry(): void
    {
        // __stripe_mid is a well-known cookie name in the bundled
        // services library (`vendor/simplecmp/services-library`). The
        // registry is empty here, so this can ONLY succeed via the
        // library lookup path.
        self::assertSame(0, $this->registry->count(), 'precondition: empty registry');

        $matches = $this->classifier->lookup('__stripe_mid', null);

        self::assertNotEmpty($matches, 'library should cover __stripe_mid');
        $ids = array_column($matches, 'id');
        self::assertContains('stripe', $ids);
    }

    #[Test]
    public function returnsEmptyWhenNeitherSourceCovers(): void
    {
        $matches = $this->classifier->lookup('_definitely_not_a_real_tracker_cookie_xyz', null);
        self::assertSame([], $matches);
    }

    #[Test]
    public function upstreamConsultedOnlyWhenRegistryAndBundledBothMiss(): void
    {
        // A cookie name that's NOT in the bundled library + empty
        // registry. ClassifierLookup must consult upstream as tier 3
        // and surface the upstream match. For a cookie the bundled
        // library DOES cover, upstream MUST NOT be called.
        $calls = [];
        $upstreamStub = $this->createMock(LibraryUpstreamClient::class);
        $upstreamStub->method('lookup')
            ->willReturnCallback(function (?string $url, ?string $cookie, ?string $origin, ?int $budget = null) use (&$calls): ?array {
                $calls[] = [$url, $cookie, $origin, $budget];
                return $cookie === '_brand_new_2026_tracker'
                    ? [['id' => 'brand-new-2026', 'name' => 'Brand New 2026']]
                    : [];
            });
        $classifier = new ClassifierLookup($this->registry, $upstreamStub);

        // Tier-3 hit when local tiers miss.
        $matches = $classifier->lookup('_brand_new_2026_tracker', null, 'https://lib.example/v1');
        self::assertCount(1, $matches);
        self::assertSame('brand-new-2026', $matches[0]['id']);
        self::assertSame(1, count($calls), 'upstream consulted once for the unknown cookie');

        // Bundled-library hit: upstream MUST NOT be called.
        $callsBefore = count($calls);
        $stripeMatches = $classifier->lookup('__stripe_mid', null, 'https://lib.example/v1');
        self::assertNotEmpty($stripeMatches);
        self::assertSame(
            $callsBefore,
            count($calls),
            'upstream MUST NOT be called when bundled library already matched',
        );
    }

    #[Test]
    public function registryWinsOverLibraryOnConflict(): void
    {
        // Admin has curated a "Stripe" with edited name + same cookie.
        // The same service_id ('stripe') exists in the bundled library
        // with the canonical "Stripe" name. ClassifierLookup must return
        // the admin's edited row.
        $this->registry->upsert([
            'id' => 'stripe',
            'name' => 'Admin-edited Stripe label',
            'purposes' => ['payment'],
            'matches' => ['cookies' => ['__stripe_mid']],
        ]);

        $matches = $this->classifier->lookup('__stripe_mid', null);

        self::assertCount(1, $matches, 'registry+library overlap should dedup to one row');
        self::assertSame('Admin-edited Stripe label', $matches[0]['name']);
    }
}
