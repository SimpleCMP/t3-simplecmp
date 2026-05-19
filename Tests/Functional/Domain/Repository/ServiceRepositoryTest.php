<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;

final class ServiceRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['wapplersystems/simplecmp-typo3'];

    private ServiceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->get(ServiceRepository::class);
    }

    // --- lookup -------------------------------------------------------------

    #[Test]
    public function lookupReturnsEmptyWhenBothCriteriaAreNull(): void
    {
        $this->seedService(['id' => 'svc', 'cookies' => ['_ga']]);
        self::assertSame([], $this->repository->lookup(null, null));
    }

    #[Test]
    public function lookupMatchesExactCookieName(): void
    {
        $this->seedService(['id' => 'google-analytics', 'name' => 'Google Analytics', 'cookies' => ['_ga']]);
        $this->seedService(['id' => 'facebook-pixel', 'name' => 'Facebook Pixel', 'cookies' => ['_fbp']]);

        $matches = $this->repository->lookup('_ga', null);
        self::assertCount(1, $matches);
        self::assertSame('google-analytics', $matches[0]['id']);
    }

    #[Test]
    public function lookupMatchesRegexCookie(): void
    {
        $this->seedService(['id' => 'google-analytics', 'cookies' => ['/^_ga/']]);
        self::assertCount(1, $this->repository->lookup('_ga_ABCD123', null));
        self::assertCount(1, $this->repository->lookup('_ga', null));
        self::assertCount(0, $this->repository->lookup('_fbp', null));
    }

    #[Test]
    public function lookupMatchesExactOrigin(): void
    {
        $this->seedService(['id' => 'youtube', 'origins' => ['youtube.com']]);
        self::assertCount(1, $this->repository->lookup(null, 'youtube.com'));
        self::assertCount(0, $this->repository->lookup(null, 'vimeo.com'));
    }

    #[Test]
    public function lookupMatchesWildcardOrigin(): void
    {
        $this->seedService(['id' => 'youtube', 'origins' => ['*.youtube.com']]);
        self::assertCount(1, $this->repository->lookup(null, 'www.youtube.com'));
        self::assertCount(1, $this->repository->lookup(null, 'i.ytimg.youtube.com'));
        self::assertCount(1, $this->repository->lookup(null, 'youtube.com'));
        self::assertCount(0, $this->repository->lookup(null, 'notyoutube.com'));
    }

    #[Test]
    public function lookupReturnsServicesMatchingEitherCriterion(): void
    {
        $this->seedService(['id' => 'cookieOnly', 'cookies' => ['_only']]);
        $this->seedService(['id' => 'originOnly', 'origins' => ['origin.example']]);

        $matches = $this->repository->lookup('_only', 'origin.example');
        $ids = array_column($matches, 'id');
        self::assertContains('cookieOnly', $ids);
        self::assertContains('originOnly', $ids);
        self::assertCount(2, $matches);
    }

    #[Test]
    public function lookupDoesNotDoubleCountServiceMatchingBothCriteria(): void
    {
        $this->seedService(['id' => 'both', 'cookies' => ['_x'], 'origins' => ['x.example']]);
        $matches = $this->repository->lookup('_x', 'x.example');
        self::assertCount(1, $matches);
        self::assertSame('both', $matches[0]['id']);
    }

    // --- pagination + counting ---------------------------------------------

    #[Test]
    public function paginateReturnsTotalAndLimitedItems(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->seedService(['id' => sprintf('svc-%02d', $i), 'name' => 'Svc ' . $i]);
        }
        $page = $this->repository->paginate(2, 3);
        self::assertSame(7, $page['total']);
        self::assertCount(3, $page['items']);
        // ordered by service_id ASC
        self::assertSame('svc-02', $page['items'][0]['id']);
        self::assertSame('svc-03', $page['items'][1]['id']);
        self::assertSame('svc-04', $page['items'][2]['id']);
    }

    #[Test]
    public function countReturnsRowCount(): void
    {
        self::assertSame(0, $this->repository->count());
        $this->seedService(['id' => 'a']);
        $this->seedService(['id' => 'b']);
        self::assertSame(2, $this->repository->count());
    }

    // --- findOne -----------------------------------------------------------

    #[Test]
    public function findOneReturnsProtocolShapeOrNull(): void
    {
        $this->seedService([
            'id' => 'matomo',
            'name' => 'Matomo',
            'vendor' => 'InnoCraft Ltd.',
            'cookies' => ['_pk_id', '_pk_ses'],
            'purposes' => ['analytics'],
        ]);

        $found = $this->repository->findOne('matomo');
        self::assertNotNull($found);
        self::assertSame('matomo', $found['id']);
        self::assertSame('Matomo', $found['name']);
        self::assertSame('InnoCraft Ltd.', $found['vendor']);
        self::assertSame(['_pk_id', '_pk_ses'], $found['matches']['cookies']);
        self::assertSame(['analytics'], $found['purposes']);

        self::assertNull($this->repository->findOne('not-here'));
    }

    // --- fe_visible --------------------------------------------------------

    #[Test]
    public function findAllVisibleOnFeReturnsOnlyFlaggedRows(): void
    {
        $this->seedService(['id' => 'hidden-a']);
        $this->seedService(['id' => 'hidden-b']);
        $this->repository->upsert(
            ['id' => 'visible-c', 'name' => 'Visible C', 'purposes' => []],
            0,
            feVisibleOnInsert: true,
        );

        $visible = $this->repository->findAllVisibleOnFe();
        self::assertSame(['visible-c'], array_column($visible, 'id'));
    }

    #[Test]
    public function upsertDoesNotChangeFeVisibleOnUpdate(): void
    {
        // Insert as visible (e.g. via Approve flow).
        $this->repository->upsert(
            ['id' => 'svc', 'name' => 'Initial', 'purposes' => []],
            0,
            feVisibleOnInsert: true,
        );
        self::assertCount(1, $this->repository->findAllVisibleOnFe());

        // Re-upsert (e.g. re-run `simplecmp:import-known-trackers --force`)
        // with feVisibleOnInsert=false. Admin's promotion must survive.
        $this->repository->upsert(
            ['id' => 'svc', 'name' => 'Updated', 'purposes' => []],
            0,
            feVisibleOnInsert: false,
        );
        $visible = $this->repository->findAllVisibleOnFe();
        self::assertCount(1, $visible);
        self::assertSame('Updated', $visible[0]['name']);
    }

    #[Test]
    public function setVisibilityPromotesAnExistingService(): void
    {
        $this->seedService(['id' => 'svc']);
        self::assertSame([], $this->repository->findAllVisibleOnFe());

        $this->repository->setVisibility('svc', true);

        $visible = $this->repository->findAllVisibleOnFe();
        self::assertCount(1, $visible);
        self::assertSame('svc', $visible[0]['id']);
    }

    #[Test]
    public function setVisibilityDemotesAVisibleService(): void
    {
        $this->repository->upsert(
            ['id' => 'svc', 'name' => 'Svc', 'purposes' => []],
            0,
            feVisibleOnInsert: true,
        );
        self::assertCount(1, $this->repository->findAllVisibleOnFe());

        $this->repository->setVisibility('svc', false);

        self::assertSame([], $this->repository->findAllVisibleOnFe());
    }

    #[Test]
    public function setVisibilityIsIdempotent(): void
    {
        $this->seedService(['id' => 'svc']);
        $this->repository->setVisibility('svc', true);
        $this->repository->setVisibility('svc', true);
        self::assertCount(1, $this->repository->findAllVisibleOnFe());
    }

    // --- helpers -----------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function seedService(array $data): void
    {
        $service = array_replace([
            'name' => $data['id'],
            'purposes' => [],
        ], $data);
        if (isset($data['cookies']) || isset($data['origins'])) {
            $service['matches'] = [];
            if (isset($data['cookies'])) {
                $service['matches']['cookies'] = $data['cookies'];
                unset($service['cookies']);
            }
            if (isset($data['origins'])) {
                $service['matches']['origins'] = $data['origins'];
                unset($service['origins']);
            }
        }
        $this->repository->upsert($service);
    }
}
