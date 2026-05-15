<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;
use WapplerSystems\SimpleCmpTypo3\Service\ServiceCurator;

/**
 * Exercises the smart-redirect lookup that the "Convert to service"
 * BE button relies on: given a detection, find an existing service
 * that already covers its cookie or origin so the admin opens that
 * record for editing instead of seeing a fresh pre-filled form.
 */
final class ServiceCuratorTest extends FunctionalTestCase
{
    private const string SERVICE_TABLE = 'tx_simplecmptypo3_service';

    protected array $testExtensionsToLoad = ['wapplersystems/simplecmp-typo3'];

    private ServiceCurator $curator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->curator = $this->get(ServiceCurator::class);
    }

    #[Test]
    public function returnsNullWhenNoServicesExist(): void
    {
        self::assertNull($this->curator->findExistingServiceUid([
            'kind' => 'cookie', 'identifier' => '_ga',
        ]));
    }

    #[Test]
    public function returnsNullWhenNoServiceMatches(): void
    {
        $this->seedService(['id' => 'facebook-pixel', 'cookies' => ['_fbp']]);
        self::assertNull($this->curator->findExistingServiceUid([
            'kind' => 'cookie', 'identifier' => '_ga',
        ]));
    }

    #[Test]
    public function returnsUidOfMatchingCookieService(): void
    {
        $this->seedService(['id' => 'google-analytics', 'cookies' => ['_ga']]);
        $expected = $this->uidOf('google-analytics');

        $uid = $this->curator->findExistingServiceUid([
            'kind' => 'cookie', 'identifier' => '_ga',
        ]);
        self::assertSame($expected, $uid);
    }

    #[Test]
    public function returnsUidOfMatchingOriginServiceForNonCookieKind(): void
    {
        $this->seedService(['id' => 'youtube', 'origins' => ['*.youtube.com']]);
        $expected = $this->uidOf('youtube');

        $uid = $this->curator->findExistingServiceUid([
            'kind' => 'iframe',
            'identifier' => 'https://www.youtube.com/embed/abc',
            'origin' => 'www.youtube.com',
        ]);
        self::assertSame($expected, $uid);
    }

    #[Test]
    public function picksMostRecentByCrdateWhenMultipleServicesOverlap(): void
    {
        // Two services both claim the cookie `_ga` (one of them is stale,
        // the other was curated by the admin yesterday). The freshly-
        // curated one should win the tiebreak.
        $this->seedService(['id' => 'stale-ga', 'cookies' => ['_ga']], crdate: time() - 86400 * 30);
        $this->seedService(['id' => 'recent-ga', 'cookies' => ['_ga']], crdate: time() - 3600);
        $recentUid = $this->uidOf('recent-ga');

        $uid = $this->curator->findExistingServiceUid([
            'kind' => 'cookie', 'identifier' => '_ga',
        ]);
        self::assertSame($recentUid, $uid);
    }

    #[Test]
    public function ignoresOriginFieldForCookieKind(): void
    {
        // The detection has both cookie kind and an origin set — the
        // curator should only match on the cookie, not let the origin
        // hit a different service.
        $this->seedService(['id' => 'cookie-svc', 'cookies' => ['_ga']]);
        $this->seedService(['id' => 'origin-svc', 'origins' => ['google-analytics.com']]);
        $expected = $this->uidOf('cookie-svc');

        $uid = $this->curator->findExistingServiceUid([
            'kind' => 'cookie',
            'identifier' => '_ga',
            'origin' => 'google-analytics.com',
        ]);
        self::assertSame($expected, $uid);
    }

    #[Test]
    public function returnsNullWhenDetectionHasNeitherCookieNorOrigin(): void
    {
        $this->seedService(['id' => 'anything', 'cookies' => ['_x']]);
        self::assertNull($this->curator->findExistingServiceUid([]));
        self::assertNull($this->curator->findExistingServiceUid(['kind' => 'cookie', 'identifier' => '']));
        self::assertNull($this->curator->findExistingServiceUid(['kind' => 'script']));
    }

    // --- helpers -----------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function seedService(array $data, ?int $crdate = null): void
    {
        $service = [
            'id' => $data['id'],
            'name' => $data['name'] ?? $data['id'],
            'purposes' => $data['purposes'] ?? [],
        ];
        if (isset($data['cookies']) || isset($data['origins'])) {
            $service['matches'] = [];
            if (isset($data['cookies'])) {
                $service['matches']['cookies'] = $data['cookies'];
            }
            if (isset($data['origins'])) {
                $service['matches']['origins'] = $data['origins'];
            }
        }
        $this->get(ServiceRepository::class)->upsert($service);

        // ServiceRepository::upsert sets crdate=time() on insert; override
        // when the test needs deterministic crdate ordering.
        if ($crdate !== null) {
            $this->get(ConnectionPool::class)
                ->getConnectionForTable(self::SERVICE_TABLE)
                ->update(self::SERVICE_TABLE, ['crdate' => $crdate], ['service_id' => $data['id']]);
        }
    }

    private function uidOf(string $serviceId): int
    {
        $uid = $this->get(ConnectionPool::class)
            ->getConnectionForTable(self::SERVICE_TABLE)
            ->createQueryBuilder()
            ->select('uid')
            ->from(self::SERVICE_TABLE)
            ->where('service_id = :id')
            ->setParameter('id', $serviceId)
            ->executeQuery()
            ->fetchOne();
        return (int) $uid;
    }
}
