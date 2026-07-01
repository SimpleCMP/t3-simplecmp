<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRegistry;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRuntimeState;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Materializes the `simplecmp.trackers` site setting into runtime
 * artefacts on every frontend page render:
 *
 *   1. Upserts a row in `tx_t3simplecmp_service` per configured
 *      tracker so the banner lists it as a managed service and the
 *      {@see \SimpleCMP\T3SimpleCmp\EventListener\CspPolicyMutator}
 *      can extend the CSP with its origins.
 *   2. Registers the remote loader script with the asset collector
 *      and stamps it with `data-name="<service_id>"` — the bundle's
 *      runtime patch reads that attribute and gates the actual src
 *      assignment on consent.
 *   3. Registers the per-site bootstrap inline JS (`_paq.push(...)`,
 *      `gtag('config', ...)`, dataLayer push) with `csp => true` so
 *      it receives the CSP nonce and doesn't trigger a violation.
 *
 * The listener runs BEFORE {@see RegisterAssets} so that the
 * service-DB rows exist when RegisterAssets calls
 * `serviceRepository->findAll()` to build the `services[]` array for
 * `cmp.init()`. Without that order, the FE would not know about
 * auto-trackers and would skip the consent gate.
 *
 * The loader scripts themselves are registered without `priority` so
 * they land in the body bucket — AFTER the bundle (which lives in
 * head priority) has installed its src-setter monkey patches.
 *
 * The upsert is unconditional: every page render rewrites the row
 * to whatever the YAML currently says. That's the contract — YAML
 * is authoritative. Editors who need a per-row tweak (different
 * cookie list, different vendor, …) should use a different
 * `serviceId` than the default and adopt that variant via the
 * library browser instead.
 */
#[AsEventListener(
    identifier: 'simplecmp/tracker-materializer',
    event: BeforeJavaScriptsRenderingEvent::class,
    before: 'SimpleCMP\\T3SimpleCmp\\EventListener\\RegisterAssets',
)]
final readonly class TrackerMaterializer
{
    public function __construct(
        private AssetCollector $assetCollector,
        private ServiceRepository $serviceRepository,
        private ManagedTrackerRepository $managedTrackerRepository,
        private TrackerRegistry $trackerRegistry,
        private TrackerRuntimeState $runtimeState,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BeforeJavaScriptsRenderingEvent $event): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return;
        }
        if (!ApplicationType::fromRequest($request)->isFrontend()) {
            return;
        }
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return;
        }

        $settings = $site->getSettings();
        if ($settings->has('simplecmp.enabled') && $settings->get('simplecmp.enabled') === false) {
            return;
        }

        // Phase-5: YAML `simplecmp.trackers` is NO LONGER auto-
        // materialized. YAML-defined trackers now show up as proposals
        // in the BE Settings tab and the editor must click "Anlegen"
        // to create a managed_tracker draft that flows through the
        // Phase-4 publish workflow. The only source of truth here is
        // tx_t3simplecmp_managed_tracker.
        $dbEntries = $this->collectManagedTrackerEntries($site->getIdentifier());

        foreach ($dbEntries as $entry) {
            $this->materializeOne($entry);
        }
    }

    /**
     * Pull BE-wizard-managed trackers for this site and flatten them
     * into the same `{type, ...config}` shape the YAML path uses.
     *
     * @return list<array<string, mixed>>
     */
    private function collectManagedTrackerEntries(string $siteIdentifier): array
    {
        $out = [];
        foreach ($this->managedTrackerRepository->findBySite($siteIdentifier) as $row) {
            $entry = $row['config'];
            $entry['type'] = $row['tracker_type'];
            if ($row['service_id'] !== '') {
                $entry['serviceId'] = $row['service_id'];
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function materializeOne(array $config): void
    {
        $type = $config['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $this->logger->warning(
                'SimpleCMP tracker entry missing "type" — skipped.',
                ['config' => $config],
            );
            return;
        }

        $provider = $this->trackerRegistry->get($type);
        if ($provider === null) {
            $this->logger->warning(
                'SimpleCMP tracker type "{type}" not registered — skipped. Known types: {known}.',
                [
                    'type' => $type,
                    'known' => implode(', ', $this->trackerRegistry->getKnownTypes()),
                ],
            );
            return;
        }

        try {
            $serviceData = $provider->buildServiceData($config);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SimpleCMP tracker "{type}" config invalid: {error}',
                ['type' => $type, 'error' => $e->getMessage()],
            );
            return;
        }

        $serviceId = isset($serviceData['id']) ? (string) $serviceData['id'] : $provider->getDefaultServiceId();

        // Upsert the service row. This is what makes
        //   (a) the banner show the tracker,
        //   (b) the CSP bridge whitelist its origins, and
        //   (c) RegisterAssets include it in the `services[]` array
        //       passed to `cmp.init({services: ...})`.
        $this->serviceRepository->upsert($serviceData);

        $loaderUrl = $provider->getLoaderUrl($config);
        if ($loaderUrl !== null) {
            // `data-name="<service_id>"` is the load-gate attribute the
            // bundle's runtime patch looks for: present → defer until
            // consent, absent → load immediately. The provider picks
            // the posture per-config (see `consentPosture` on Ga4 /
            // GTM): `block` keeps the gate; `signal-gate` drops it so
            // the tag can run pre-consent and the engine's Consent
            // Mode v2 hook owns the gating instead.
            $attributes = ['async' => 'async'];
            if ($provider->wantsLoadGate($config)) {
                $attributes['data-name'] = $serviceId;
                // Managed trackers are invisible background scripts
                // (analytics/pixels), never visual embeds — so the bundle
                // must NOT auto-insert a "load external content?" contextual
                // notice next to the gated <script>. Without this, a blocked
                // tracker renders an empty ~180px notice card in the body
                // that lengthens the page while showing nothing useful
                // pre-consent. `data-no-placeholder` is the bundle's
                // per-element opt-out (see engine `_toggleAutoPlaceholder`).
                // The banner still lists the tracker for consent as usual.
                $attributes['data-no-placeholder'] = '1';
            }
            $this->assetCollector->addJavaScript(
                'simplecmp-tracker-loader-' . $serviceId,
                $loaderUrl,
                $attributes,
                // No `priority` — must land in the body so the bundle
                // (head priority) has already installed its src-setter
                // monkey patches. `csp => true` so the rendered <script>
                // tag receives the nonce.
                ['csp' => true],
            );
        }

        // Signal the init-config builder (RegisterAssets, which runs
        // after this listener on the same event) to forward
        // `consentMode` into `cmp.init()` so the engine hook emits the
        // v2 `default (denied)` AND the `update (granted)` on accept.
        // ADR-0017: providers that also implement {@see ConsentVendorAware}
        // contribute their vendor key (`google`/`meta`/`microsoftUet`)
        // so RegisterAssets can emit the multi-vendor
        // `consentMode: { vendors: [...] }` shape. Providers that
        // pre-date the interface fall back to the legacy
        // `consentMode: true` (= Google-only) form.
        if ($provider->wantsConsentMode($config)) {
            if ($provider instanceof \SimpleCMP\T3SimpleCmp\Tracker\ConsentVendorAware) {
                $this->runtimeState->addConsentVendor($provider->getConsentVendor($config));
            } else {
                $this->runtimeState->requestConsentMode();
            }
        }

        $bootstrap = $provider->getBootstrapInlineScript($config);
        if ($bootstrap !== '') {
            $this->assetCollector->addInlineJavaScript(
                'simplecmp-tracker-bootstrap-' . $serviceId,
                $bootstrap,
                [],
                ['csp' => true],
            );
        }
    }
}
