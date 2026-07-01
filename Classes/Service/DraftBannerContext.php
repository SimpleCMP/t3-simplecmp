<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;

/**
 * Phase-4 helper: pre-builds the array of template arguments the
 * `DraftBanner.html` partial needs. Controllers call
 * `$bannerContext->forScope($scope, $request)` and assign the result
 * via `$moduleTemplate->assignMultiple($result)`.
 *
 * Centralises the "scope → lock state + has-draft + publish URIs"
 * dance so each controller doesn't redo it.
 */
final readonly class DraftBannerContext
{
    public function __construct(
        private DraftWorkspaceService $workspace,
        private BackendUriBuilder $backendUriBuilder,
    ) {
    }

    /**
     * @return array{
     *     lockState: LockState,
     *     hasDraft: bool,
     *     isDraftEditable: bool,
     *     currentBeUserId: int,
     *     scope: string,
     *     uri_publish: string,
     *     uri_discard: string,
     *     uri_takeover: string,
     *     uri_createDraft: string,
     *     currentUrl: string,
     * }
     */
    public function forScope(string $scope, ?ServerRequestInterface $request = null): array
    {
        return $this->build(
            $scope,
            $this->workspace->currentLock($scope),
            $this->workspace->hasDraft($scope),
            $this->workspace->draftRevision($scope),
            $request,
        );
    }

    /**
     * Umbrella variant: banner state for a site's UNIFIED draft, spanning
     * the shared global service scope AND the per-site scope. Every
     * draft-gated tab uses this so they all report the same state. The
     * `scope` posted by the banner/action-bar forms is the site itself —
     * PublishController turns that into the umbrella publish/discard/init.
     *
     * @return array{lockState: LockState, hasDraft: bool, isDraftEditable: bool, currentBeUserId: int, scope: string, draftRevision: int, uri_publish: string, uri_discard: string, uri_takeover: string, uri_createDraft: string, currentUrl: string}
     */
    public function forSite(string $site, ?ServerRequestInterface $request = null): array
    {
        return $this->build(
            $site,
            $this->workspace->lockForSite($site),
            $this->workspace->hasDraftForSite($site),
            $this->workspace->draftRevisionForSite($site),
            $request,
        );
    }

    /**
     * @return array{lockState: LockState, hasDraft: bool, isDraftEditable: bool, currentBeUserId: int, scope: string, draftRevision: int, uri_publish: string, uri_discard: string, uri_takeover: string, uri_createDraft: string, currentUrl: string}
     */
    private function build(string $scope, LockState $lockState, bool $hasDraft, int $draftRevision, ?ServerRequestInterface $request): array
    {
        $beUserId = (int) ($GLOBALS['BE_USER']->user['uid'] ?? 0);
        if ($request !== null) {
            $uri = $request->getUri();
            $currentUrl = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
        } else {
            $currentUrl = '';
        }
        return [
            'lockState' => $lockState,
            'hasDraft' => $hasDraft,
            'isDraftEditable' => $hasDraft && !$lockState->conflict,
            'currentBeUserId' => $beUserId,
            'scope' => $scope,
            'draftRevision' => $draftRevision,
            'uri_publish' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Publish_publish',
            ),
            'uri_discard' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Publish_discard',
            ),
            'uri_takeover' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Publish_takeover',
            ),
            'uri_createDraft' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Publish_init',
            ),
            'currentUrl' => $currentUrl,
        ];
    }
}
