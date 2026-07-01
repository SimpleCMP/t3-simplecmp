<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller;

use Psr\Http\Message\ResponseInterface;
use SimpleCMP\T3SimpleCmp\Service\DraftPublishService;
use SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService;
use SimpleCMP\T3SimpleCmp\Service\LockState;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Phase-4 publish / discard / takeover BE actions.
 *
 * Every action redirects back to the originating tab — the controller
 * doesn't render its own template. Edits live in their respective
 * controllers (Registry, Theme, …); this controller is the one
 * place that promotes drafts to live state.
 *
 * Scope semantics:
 *   - `scope = __global__` → service registry (one global lock)
 *   - `scope = <site-identifier>` → per-site theme + overrides +
 *     trackers + allowed hosts (one lock per site)
 */
final class PublishController extends ActionController
{
    public function __construct(
        private readonly DraftPublishService $publishService,
        private readonly DraftWorkspaceService $workspace,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {
    }

    /**
     * Promote the draft for $scope into live. Redirects to the
     * returnUrl when provided (the originating tab); falls back to
     * the audit list view otherwise.
     */
    public function publishAction(string $scope, string $returnUrl = ''): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0) {
            $this->addFlashMessage(
                'Publish ist nur als angemeldeter BE-User möglich.',
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectAfter($returnUrl);
        }

        $conflict = $this->conflictingLock($scope, $beUserId);
        if ($conflict !== null) {
            $this->addFlashMessage(
                sprintf('Lock für "%s" gehört BE-User uid=%d — Publish abgelehnt.', $scope, $conflict->ownerBeUserId),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectAfter($returnUrl);
        }

        try {
            $result = $this->publishService->publishForSite($scope, $beUserId);
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                sprintf('Publish-Fehler: %s', $e->getMessage()),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectAfter($returnUrl);
        }

        if ($result->noOp) {
            $this->addFlashMessage(
                sprintf('Keine Entwurfsdaten für "%s" — nichts zu veröffentlichen.', $scope),
                '',
                ContextualFeedbackSeverity::INFO,
            );
        } else {
            $hashPart = $result->snapshotHash === null
                ? ''
                : sprintf(' Snapshot-Hash: %s…', substr($result->snapshotHash, 0, 12));
            $this->addFlashMessage(
                sprintf(
                    'Entwurf für "%s" veröffentlicht. %d Tabellen-Operationen.%s',
                    $scope,
                    count($result->perTable),
                    $hashPart,
                ),
                '',
                ContextualFeedbackSeverity::OK,
            );
        }
        return $this->redirectAfter($returnUrl);
    }

    /**
     * Drop the draft + release the lock. No promotion.
     */
    public function discardAction(string $scope, string $returnUrl = ''): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        $conflict = $this->conflictingLock($scope, $beUserId);
        if ($conflict !== null) {
            $this->addFlashMessage(
                sprintf('Lock für "%s" gehört BE-User uid=%d — Verwerfen abgelehnt.', $scope, $conflict->ownerBeUserId),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectAfter($returnUrl);
        }
        $this->workspace->discardDraftForSite($scope);
        $this->addFlashMessage(
            sprintf('Entwurf für "%s" verworfen.', $scope),
            '',
            ContextualFeedbackSeverity::OK,
        );
        return $this->redirectAfter($returnUrl);
    }

    /**
     * Take over the lock from another editor. Discards their draft
     * data before reassigning the lock to the current user.
     */
    public function takeoverAction(string $scope, string $returnUrl = ''): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0) {
            return $this->redirectAfter($returnUrl);
        }
        // Discard the existing draft first (their work loses, by design)
        $this->workspace->discardDraftForSite($scope);
        foreach ($this->workspace->relatedScopes($scope) as $relatedScope) {
            $this->workspace->takeoverLock($relatedScope, $beUserId);
        }
        $this->addFlashMessage(
            sprintf('Lock für "%s" übernommen — der vorherige Entwurf wurde verworfen.', $scope),
            '',
            ContextualFeedbackSeverity::WARNING,
        );
        return $this->redirectAfter($returnUrl);
    }

    /**
     * Initialise a new draft for $scope (Variante A: create-on-demand by
     * the editor via an explicit "Create draft" button).
     */
    public function initAction(string $scope, string $returnUrl = ''): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0) {
            $this->addFlashMessage(
                'Entwurf anlegen ist nur als angemeldeter BE-User möglich.',
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectAfter($returnUrl);
        }
        $lock = $this->workspace->initializeDraftForSite($scope, $beUserId);
        if ($lock->conflict) {
            $this->addFlashMessage(
                sprintf(
                    'Lock für "%s" gehört BE-User uid=%d — Entwurf-Initialisierung abgelehnt.',
                    $scope,
                    $lock->ownerBeUserId,
                ),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
        } else {
            $this->addFlashMessage(
                sprintf('Entwurf für "%s" angelegt.', $scope),
                '',
                ContextualFeedbackSeverity::OK,
            );
        }
        return $this->redirectAfter($returnUrl);
    }

    /**
     * First lock in the site's umbrella (global + per-site) that is held
     * by a DIFFERENT BE user, or null when every related scope is free or
     * owned by this user. Used to gate publish/discard for the whole
     * umbrella, not just one scope.
     */
    private function conflictingLock(string $scope, int $beUserId): ?\SimpleCMP\T3SimpleCmp\Service\LockState
    {
        foreach ($this->workspace->relatedScopes($scope) as $relatedScope) {
            $lock = $this->workspace->currentLock($relatedScope);
            if (!$lock->isUnlocked() && !$lock->isOwnedBy($beUserId)) {
                return $lock;
            }
        }
        return null;
    }

    private function currentBeUserId(): int
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUser === null || !isset($beUser->user['uid'])) {
            return 0;
        }
        return (int) $beUser->user['uid'];
    }

    private function redirectAfter(string $returnUrl): ResponseInterface
    {
        if ($returnUrl !== '' && str_starts_with($returnUrl, '/')) {
            return $this->responseFactory->createResponse(303)
                ->withHeader('Location', $returnUrl);
        }
        // Fallback: audit list
        $fallback = (string) $this->backendUriBuilder->buildUriFromRoute(
            'simplecmp_detections.AuditSnapshot_list',
        );
        return $this->responseFactory->createResponse(303)
            ->withHeader('Location', $fallback);
    }
}
