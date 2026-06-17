<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService;
use SimpleCMP\T3SimpleCmp\Service\LockState;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Phase-4 functional tests for copy-on-write of {@see DraftWorkspaceService}.
 *
 * Exercises the live→draft INSERT path against a real MySQL instance —
 * verifies that seeded live rows end up in the matching draft tables
 * with the workspace bookkeeping columns populated.
 */
final class DraftWorkspaceServiceCopyTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['simplecmp/t3-simplecmp'];

    private DraftWorkspaceService $workspace;
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = $this->get(DraftWorkspaceService::class);
        $this->connectionPool = $this->get(ConnectionPool::class);
    }

    #[Test]
    public function initializeDraftCopiesGlobalServicesIntoDraftTable(): void
    {
        $this->insertService('matomo', 'Matomo Analytics');
        $this->insertService('google-fonts', 'Google Fonts');

        $lock = $this->workspace->initializeDraft(LockState::SCOPE_GLOBAL, 42);

        self::assertFalse($lock->conflict);
        self::assertSame(42, $lock->ownerBeUserId);
        self::assertTrue($this->workspace->hasDraft(LockState::SCOPE_GLOBAL));

        $drafts = $this->draftServices();
        self::assertCount(2, $drafts);
        $byId = array_column($drafts, null, 'service_id');
        self::assertArrayHasKey('matomo', $byId);
        self::assertSame('', $byId['matomo']['draft_site']);
        self::assertSame(42, (int) $byId['matomo']['draft_owner_be_user']);
        self::assertGreaterThan(0, (int) $byId['matomo']['draft_modified_at']);
    }

    #[Test]
    public function initializeDraftPerSiteCopiesTheme(): void
    {
        $this->insertTheme('default', '{"color-primary":"#abc"}');
        $this->insertTheme('other', '{"color-primary":"#def"}');

        $this->workspace->initializeDraft('default', 7);

        $drafts = $this->draftThemes();
        // Only the 'default' row gets copied; 'other' stays untouched
        self::assertCount(1, $drafts);
        self::assertSame('default', $drafts[0]['site']);
        self::assertSame('default', $drafts[0]['draft_site']);
        self::assertSame(7, (int) $drafts[0]['draft_owner_be_user']);
    }

    #[Test]
    public function initializeDraftIsIdempotent(): void
    {
        $this->insertService('matomo', 'Matomo Analytics');

        $this->workspace->initializeDraft(LockState::SCOPE_GLOBAL, 42);
        $firstCount = count($this->draftServices());

        // Second invocation should NOT duplicate the rows
        $this->workspace->initializeDraft(LockState::SCOPE_GLOBAL, 42);
        $secondCount = count($this->draftServices());

        self::assertSame($firstCount, $secondCount);
    }

    #[Test]
    public function initializeDraftReturnsConflictWhenLockHeldByOtherUser(): void
    {
        $this->insertService('matomo', 'Matomo Analytics');
        // Editor 1 starts the draft first
        $this->workspace->initializeDraft(LockState::SCOPE_GLOBAL, 1);

        // Editor 2 tries → should get a conflict back, no extra copy
        $lock = $this->workspace->initializeDraft(LockState::SCOPE_GLOBAL, 2);

        self::assertTrue($lock->conflict);
        self::assertSame(1, $lock->ownerBeUserId);
        // Drafts owned by editor 1 (not duplicated by editor 2)
        $drafts = $this->draftServices();
        foreach ($drafts as $row) {
            self::assertSame(1, (int) $row['draft_owner_be_user']);
        }
    }

    #[Test]
    public function discardDraftClearsRowsAndReleasesLock(): void
    {
        $this->insertService('matomo', 'Matomo Analytics');
        $this->workspace->initializeDraft(LockState::SCOPE_GLOBAL, 42);

        self::assertTrue($this->workspace->hasDraft(LockState::SCOPE_GLOBAL));

        $this->workspace->discardDraft(LockState::SCOPE_GLOBAL);

        self::assertFalse($this->workspace->hasDraft(LockState::SCOPE_GLOBAL));
        self::assertTrue($this->workspace->currentLock(LockState::SCOPE_GLOBAL)->isUnlocked());
        self::assertCount(0, $this->draftServices());
    }

    #[Test]
    public function discardDraftIsIdempotentWhenNoDraftExists(): void
    {
        // No init, just discard — must not throw
        $this->workspace->discardDraft('default');
        self::assertFalse($this->workspace->hasDraft('default'));
    }

    // --- DB helpers --------------------------------------------------------

    private function insertService(string $serviceId, string $name): void
    {
        $this->connectionPool->getConnectionForTable('tx_t3simplecmp_service')->insert(
            'tx_t3simplecmp_service',
            [
                'service_id' => $serviceId,
                'name' => $name,
                'description' => 'test fixture',
                'purposes' => '[]',
                'cookies' => '[]',
                'origins' => '[]',
                'crdate' => time(),
            ],
        );
    }

    private function insertTheme(string $site, string $tokensJson): void
    {
        $this->connectionPool->getConnectionForTable('tx_t3simplecmp_theme')->insert(
            'tx_t3simplecmp_theme',
            ['site' => $site, 'tokens' => $tokensJson, 'crdate' => time()],
        );
    }

    /** @return list<array<string, mixed>> */
    private function draftServices(): array
    {
        return $this->connectionPool->getConnectionForTable('tx_t3simplecmp_service_draft')
            ->select(['*'], 'tx_t3simplecmp_service_draft')
            ->fetchAllAssociative();
    }

    /** @return list<array<string, mixed>> */
    private function draftThemes(): array
    {
        return $this->connectionPool->getConnectionForTable('tx_t3simplecmp_theme_draft')
            ->select(['*'], 'tx_t3simplecmp_theme_draft')
            ->fetchAllAssociative();
    }
}