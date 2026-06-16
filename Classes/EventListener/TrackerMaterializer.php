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
use TYPO3\CMS\Core\Settings\SettingsInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ArrayUtility;

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

        // Two sources of truth, merged in order:
        //   1. YAML `simplecmp.trackers` — integrator-owned, git-versioned
        //   2. `tx_t3simplecmp_managed_tracker` — BE-wizard-owned
        //
        // YAML wins on `serviceId` collision because file-based config
        // is the ops-emergency override path. Editors who hit a clash
        // get a warning in the log and a hint in the BE wizard.
        $yamlEntries = $this->collectTrackerEntries($settings);
        $dbEntries = $this->collectManagedTrackerEntries($site->getIdentifier());

        $seenServiceIds = [];
        $pipeline = [];

        foreach ($yamlEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $candidateId = $this->resolveServiceId($entry);
            if ($candidateId !== null) {
                $seenServiceIds[$candidateId] = 'yaml';
            }
            $pipeline[] = $entry;
        }

        foreach ($dbEntries as $entry) {
            $candidateId = $this->resolveServiceId($entry);
            if ($candidateId !== null && isset($seenServiceIds[$candidateId])) {
                $this->logger->warning(
                    'BE-managed tracker "{id}" collides with YAML — YAML wins, BE row skipped.',
                    ['id' => $candidateId],
                );
                continue;
            }
            if ($candidateId !== null) {
                $seenServiceIds[$candidateId] = 'db';
            }
            $pipeline[] = $entry;
        }

        foreach ($pipeline as $entry) {
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
     * Best-effort service_id resolution from a raw config entry —
     * mirrors `materializeOne()`'s lookup but without throwing on
     * unknown / invalid config (which `materializeOne()` then handles
     * with a logger warning).
     *
     * @param array<string, mixed> $config
     */
    private function resolveServiceId(array $config): ?string
    {
        if (isset($config['serviceId']) && is_string($config['serviceId']) && $config['serviceId'] !== '') {
            return $config['serviceId'];
        }
        $type = $config['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return null;
        }
        $provider = $this->trackerRegistry->get($type);
        return $provider?->getDefaultServiceId();
    }

    /**
     * Reconstruct the `simplecmp.trackers` list from TYPO3's flat
     * settings store. Site settings without a definition entry are
     * exposed dot-flattened (`simplecmp.trackers.0.type`,
     * `simplecmp.trackers.0.url`, …) instead of as a nested array —
     * `Settings::get('simplecmp.trackers')` would throw
     * `SettingNotFoundException` because the bare key has no value.
     *
     * Collect every flat key under the `simplecmp.trackers.` prefix,
     * run them through {@see ArrayUtility::unflatten()}, then return
     * the inner `[trackers]` list.
     *
     * @return list<array<string, mixed>>
     */
    private function collectTrackerEntries(SettingsInterface $settings): array
    {
        $prefix = 'simplecmp.trackers.';
        $flat = [];
        foreach ($settings->getIdentifiers() as $identifier) {
            if (str_starts_with($identifier, $prefix)) {
                $flat[$identifier] = $settings->get($identifier);
            }
        }
        if ($flat === []) {
            return [];
        }
        $tree = ArrayUtility::unflatten($flat);
        $list = $tree['simplecmp']['trackers'] ?? null;
        if (!is_array($list)) {
            return [];
        }
        return array_values($list);
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
