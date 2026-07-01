<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use SimpleCMP\T3SimpleCmp\Service\DraftPublishService;
use SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService;
use SimpleCMP\T3SimpleCmp\Service\LockState;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Phase-4 functional test for {@see DraftPublishService}: verifies
 * atomic draft → live promotion against the real DB.
 */
final class DraftPublishServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['simplecmp/t3-simplecmp'];

    private DraftPublishService $publishService;
    private DraftWorkspaceService $workspace;
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishService = $this->get(DraftPublishService::class);
        $this->workspace = $this->get(DraftWorkspaceService::class);
        $this->connectionPool = $this->get(ConnectionPool::class);
    }

    #[Test]
    public function publishWithoutDraftIsNoOp(): void
    {
        $result = $this->publishService->publish(LockState::SCOPE_GLOBAL, 42);
        self::assertTrue($result->noOp);
        self::assertNull($result->snapshotHash);
    }

    #[Test]
    public function publishPromotesGlobalServiceDraftToLive(): void
    {
        $this->insertService('matomo', 'Matomo Analytics');
        $this->workspace->initializeDraft(LockState::SCOPE_GLOBAL, 42);
        // Edit the draft: rename matomo
        $this->renameDraftService('matomo', 'Matomo Pro');

        $result = $this->publishService->publish(LockState::SCOPE_GLOBAL, 42);

        self::assertFalse($result->noOp);
        // Live now reflects the rename
        $live = $this->liveServices();
        self::assertCount(1, $live);
        self::assertSame('Matomo Pro', $live[0]['name']);
        // Draft cleared
        self::assertFalse($this->workspace->hasDraft(LockState::SCOPE_GLOBAL));
        // Lock released
        self::assertTrue($this->workspace->currentLock(LockState::SCOPE_GLOBAL)->isUnlocked());
    }

    #[Test]
    public function publishDeletesLiveRowsThatVanishedFromDraft(): void
    {
        $this->insertService('matomo', 'Matomo Analytics');
        $this->insertService('youtube', 'YouTube');
        $this->workspace->initializeDraft(LockState::SCOPE_GLOBAL, 42);
        // Drop matomo from the draft → publish should DELETE it from live
        $this->deleteDraftService('matomo');

        $this->publishService->publish(LockState::SCOPE_GLOBAL, 42);

        $live = $this->liveServices();
        self::assertCount(1, $live);
        self::assertSame('youtube', $live[0]['service_id']);
    }

    #[Test]
    public function publishPerSiteOnlyReplacesThatSitesRows(): void
    {
        $this->insertTheme('default', '{"color-primary":"#abc"}');
        $this->insertTheme('other', '{"color-primary":"#def"}');
        $this->workspace->initializeDraft('default', 7);
        $this->setDraftTokens('default', '{"color-primary":"#changed"}');

        $this->publishService->publish('default', 7);

        $themes = $this->liveThemes();
        $bySite = array_column($themes, 'tokens', 'site');
        self::assertStringContainsString('changed', (string) $bySite['default']);
        // 'other' site untouched
        self::assertStringContainsString('#def', (string) $bySite['other']);
    }

    #[Test]
    public function publishForSitePromotesBothGlobalAndPerSiteScopes(): void
    {
        // A global service and a per-site theme, opened as ONE umbrella
        // draft. publishForSite() must promote BOTH — the per-site scope
        // is what a global-only publish used to skip (the tracker/theme
        // "publish did nothing" bug).
        $this->insertService('matomo', 'Matomo Analytics');
        $this->insertTheme('default', '{"color-primary":"#abc"}');
        $this->workspace->initializeDraftForSite('default', 7);
        $this->renameDraftService('matomo', 'Matomo Pro');
        $this->setDraftTokens('default', '{"color-primary":"#changed"}');

        $result = $this->publishService->publishForSite('default', 7);

        self::assertFalse($result->noOp);
        // Global service scope promoted.
        $services = $this->liveServices();
        self::assertSame('Matomo Pro', $services[0]['name']);
        // Per-site scope promoted (a global-only publish never touched it).
        $themes = array_column($this->liveThemes(), 'tokens', 'site');
        self::assertStringContainsString('changed', (string) $themes['default']);
        // Both scopes cleared + unlocked.
        self::assertFalse($this->workspace->hasDraftForSite('default'));
        self::assertTrue($this->workspace->currentLock('default')->isUnlocked());
        self::assertTrue($this->workspace->currentLock(LockState::SCOPE_GLOBAL)->isUnlocked());
    }

    // --- DB helpers --------------------------------------------------------

    private function insertService(string $serviceId, string $name): void
    {
        $this->connectionPool->getConnectionForTable('tx_t3simplecmp_service')->insert(
            'tx_t3simplecmp_service',
            [
                'service_id' => $serviceId,
                'name' => $name,
                'description' => 'fixture',
                'purposes' => '[]',
                'cookies' => '[]',
                'origins' => '[]',
                'crdate' => time(),
            ],
        );
    }

    private function renameDraftService(string $serviceId, string $newName): void
    {
        $this->connectionPool->getConnectionForTable('tx_t3simplecmp_service_draft')->update(
            'tx_t3simplecmp_service_draft',
            ['name' => $newName],
            ['service_id' => $serviceId],
        );
    }

    private function deleteDraftService(string $serviceId): void
    {
        $this->connectionPool->getConnectionForTable('tx_t3simplecmp_service_draft')->delete(
            'tx_t3simplecmp_service_draft',
            ['service_id' => $serviceId],
        );
    }

    private function insertTheme(string $site, string $tokensJson): void
    {
        $this->connectionPool->getConnectionForTable('tx_t3simplecmp_theme')->insert(
            'tx_t3simplecmp_theme',
            ['site' => $site, 'tokens' => $tokensJson, 'crdate' => time()],
        );
    }

    private function setDraftTokens(string $site, string $tokensJson): void
    {
        $this->connectionPool->getConnectionForTable('tx_t3simplecmp_theme_draft')->update(
            'tx_t3simplecmp_theme_draft',
            ['tokens' => $tokensJson],
            ['site' => $site],
        );
    }

    /** @return list<array<string, mixed>> */
    private function liveServices(): array
    {
        return $this->connectionPool->getConnectionForTable('tx_t3simplecmp_service')
            ->select(['*'], 'tx_t3simplecmp_service')
            ->fetchAllAssociative();
    }

    /** @return list<array<string, mixed>> */
    private function liveThemes(): array
    {
        return $this->connectionPool->getConnectionForTable('tx_t3simplecmp_theme')
            ->select(['*'], 'tx_t3simplecmp_theme')
            ->fetchAllAssociative();
    }
}