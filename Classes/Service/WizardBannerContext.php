<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Companion to {@see DraftBannerContext}: pre-builds the variables the
 * `WizardBanner.html` partial needs. Controllers call
 * `$wizardBannerContext->forSite($site)` (when the controller already
 * has a chosen site) or `$wizardBannerContext->forAnyPendingSite()`
 * (for tabs without a site-picker like Detections / Audit).
 *
 * The banner is the trigger for the linear onboarding wizard. Hidden
 * automatically once the wizard is completed or skipped — see
 * {@see WizardStateService::shouldShowBanner()}.
 */
final readonly class WizardBannerContext
{
    private const string SET_IDENTIFIER = 'simplecmp/t3-simplecmp';

    public function __construct(
        private WizardStateService $wizardState,
        private BackendUriBuilder $backendUriBuilder,
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * @return array{
     *     wizardBannerVisible: bool,
     *     wizardBannerSite: string,
     *     uri_wizardStart: string,
     * }
     */
    public function forSite(string $site): array
    {
        $visible = $site !== '' && $this->wizardState->shouldShowBanner($site);
        return $this->build($site, $visible);
    }

    /**
     * Returns context for the first SimpleCMP-enabled site whose
     * onboarding banner should still be shown — used by tabs that
     * don't have a site-picker (Detections, Audit). If no site needs
     * the banner, returns visible=false.
     *
     * @return array{
     *     wizardBannerVisible: bool,
     *     wizardBannerSite: string,
     *     uri_wizardStart: string,
     * }
     */
    public function forAnyPendingSite(): array
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (!in_array(self::SET_IDENTIFIER, $site->getSets(), true)) {
                continue;
            }
            $identifier = $site->getIdentifier();
            if ($this->wizardState->shouldShowBanner($identifier)) {
                return $this->build($identifier, true);
            }
        }
        return $this->build('', false);
    }

    /**
     * @return array{
     *     wizardBannerVisible: bool,
     *     wizardBannerSite: string,
     *     uri_wizardStart: string,
     * }
     */
    private function build(string $site, bool $visible): array
    {
        $uri = '';
        if ($visible) {
            $uri = (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.SetupWizard_welcome',
                ['site' => $site],
            );
        }
        return [
            'wizardBannerVisible' => $visible,
            'wizardBannerSite' => $site,
            'uri_wizardStart' => $uri,
        ];
    }
}
