<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;
use WapplerSystems\SimpleCmpTypo3\Service\ClassifierLookup;

/**
 * Lookups against an empty registry MUST still return library coverage.
 * That's the whole point of consulting both sources — admin can curate
 * an empty registry while the classifier silently knows about every
 * common third-party cookie via the bundled JSON library.
 */
final class ClassifierLookupTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['wapplersystems/simplecmp-typo3'];

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
