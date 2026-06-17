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
     *     currentBeUserId: int,
     *     scope: string,
     *     uri_publish: string,
     *     uri_discard: string,
     *     uri_takeover: string,
     *     currentUrl: string,
     * }
     */
    public function forScope(string $scope, ?ServerRequestInterface $request = null): array
    {
        $beUserId = (int) ($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $currentUrl = $request !== null ? (string) $request->getUri() : '';
        return [
            'lockState' => $this->workspace->currentLock($scope),
            'hasDraft' => $this->workspace->hasDraft($scope),
            'currentBeUserId' => $beUserId,
            'scope' => $scope,
            'uri_publish' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Publish_publish',
            ),
            'uri_discard' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Publish_discard',
            ),
            'uri_takeover' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Publish_takeover',
            ),
            'currentUrl' => $currentUrl,
        ];
    }
}
