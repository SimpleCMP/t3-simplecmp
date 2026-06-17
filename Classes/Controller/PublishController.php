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

        $lock = $this->workspace->currentLock($scope);
        if (!$lock->isUnlocked() && !$lock->isOwnedBy($beUserId)) {
            $this->addFlashMessage(
                sprintf('Lock für "%s" gehört BE-User uid=%d — Publish abgelehnt.', $scope, $lock->ownerBeUserId),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectAfter($returnUrl);
        }

        try {
            $result = $this->publishService->publish($scope, $beUserId);
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
        $lock = $this->workspace->currentLock($scope);
        if (!$lock->isUnlocked() && !$lock->isOwnedBy($beUserId)) {
            $this->addFlashMessage(
                sprintf('Lock für "%s" gehört BE-User uid=%d — Verwerfen abgelehnt.', $scope, $lock->ownerBeUserId),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectAfter($returnUrl);
        }
        $this->publishService->discard($scope);
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
        $this->publishService->discard($scope);
        $this->workspace->takeoverLock($scope, $beUserId);
        $this->addFlashMessage(
            sprintf('Lock für "%s" übernommen — der vorherige Entwurf wurde verworfen.', $scope),
            '',
            ContextualFeedbackSeverity::WARNING,
        );
        return $this->redirectAfter($returnUrl);
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
