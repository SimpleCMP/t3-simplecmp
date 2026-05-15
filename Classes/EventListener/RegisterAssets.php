<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeNonceService;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeSecretProvider;

/**
 * Registers the SimpleCMP JS bundle + inline init call on every TYPO3
 * frontend page that has the SimpleCMP site set enabled.
 *
 * Listens on `BeforeJavaScriptsRenderingEvent` (Core's PageRenderer
 * dispatches this before composing the final <script> output) so the
 * additions land in the rendered HTML without TypoScript or middleware
 * gymnastics. Backend requests are filtered out via `ApplicationType`.
 *
 * Config flows from Site Settings (see
 * `Configuration/Sets/SimpleCmp/settings.definitions.yaml`) into the
 * `SimpleCMP.init(...)` call as a JSON-encoded object.
 */
#[AsEventListener(
    identifier: 'simplecmp-typo3/register-assets',
    event: BeforeJavaScriptsRenderingEvent::class,
)]
final readonly class RegisterAssets
{
    public function __construct(
        private AssetCollector $assetCollector,
        private ServiceRepository $serviceRepository,
        private BridgeSecretProvider $secretProvider,
        private BridgeNonceService $nonceService,
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
        if ($settings->get('simplecmp.enabled') === false) {
            return;
        }

        $config = $this->buildInitConfig($settings, $site);
        if ($config === null) {
            return;
        }

        // Bundle first — the IIFE exposes `window.SimpleCMP`.
        $this->assetCollector->addJavaScript(
            'simplecmp-bundle',
            'EXT:simplecmp_typo3/Resources/Public/JavaScript/simplecmp.global.js',
        );

        // Inline init right after — AssetCollector preserves insertion order
        // within each category.
        $this->assetCollector->addInlineJavaScript(
            'simplecmp-init',
            sprintf(
                'window.SimpleCMP && window.SimpleCMP.init(%s);',
                json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ),
        );
    }

    /**
     * Map Site Settings → SimpleCMPConfig shape. Returns null when the
     * config is too incomplete to bother mounting (no privacy policy and
     * no services configured).
     *
     * @return array<string, mixed>|null
     */
    private function buildInitConfig(object $settings, Site $site): ?array
    {
        $get = static fn (string $key, mixed $default = null): mixed
            => $settings->get($key) ?: $default;

        [$services, $serviceTranslations] = $this->buildRuntimeServices();
        $config = [
            'storageName' => $get('simplecmp.storageName') ?: 'simplecmp-' . $site->getIdentifier(),
            'services' => $services,
            'respectGPC' => (bool) $get('simplecmp.respectGPC', true),
            // Show "Accept all" alongside "Decline" and "Save selected" in the
            // modal footer so returning users can bulk-toggle from the trigger.
            'acceptAll' => true,
            'floatingTrigger' => [
                'label' => (string) $get('simplecmp.floatingTriggerLabel', 'Cookie settings'),
            ],
        ];
        if ($serviceTranslations !== []) {
            $config['translations'] = $serviceTranslations;
        }

        $privacy = (string) $get('simplecmp.privacyPolicyUrl', '');
        if ($privacy !== '') {
            $config['privacyPolicy'] = $privacy;
        }

        $imprint = (string) $get('simplecmp.imprintUrl', '');
        if ($imprint !== '') {
            $config['imprint'] = $imprint;
        }

        $serviceDbUrl = (string) $get('simplecmp.serviceDbUrl', '');
        if ($serviceDbUrl !== '') {
            $config['serviceDbUrl'] = $this->normalizeServiceDbUrl($serviceDbUrl);
            // The recorder must be running for the bridge to fire — opt
            // into record mode automatically when an admin has configured
            // a remote data sink (service DB or CMS bridge).
            $config['record'] = ['silenceProductionWarning' => true];
        }

        $cmsBridgeUrl = (string) $get('simplecmp.cmsBridgeUrl', '');
        if ($cmsBridgeUrl !== '') {
            if (!$this->secretProvider->isConfigured()) {
                // Refuse-until-configured. The bridge would otherwise POST
                // unauthenticated traffic to a receiver that should reject
                // it — silent breakage. Surface clearly and skip the bridge
                // config entirely so the rest of SimpleCMP still works.
                $this->logger->warning(
                    'SimpleCMP cmsBridgeUrl is configured but bridgeSecret is missing — '
                    . 'bridge will not be enabled on this site. Run '
                    . '`vendor/bin/typo3 simplecmp:generate-bridge-secret` and follow '
                    . 'the printed instructions.',
                );
            } else {
                $config['cmsBridgeUrl'] = $cmsBridgeUrl;
                $config['cmsBridgeAuth'] = [
                    'token' => $this->nonceService->issue((string) $config['storageName']),
                ];
                $config['record'] = ['silenceProductionWarning' => true];
            }
        }

        if ($privacy === '' && $config['services'] === []) {
            // Nothing useful to mount — skip the asset registration so
            // we don't ship dead JS on every page.
            return null;
        }

        return $config;
    }

    /**
     * Defensive normalization for the `serviceDbUrl` Site Set setting.
     *
     * The SimpleCMP JS client appends `/v1/<endpoint>` itself, so the
     * configured value must be the base URL **without** the protocol-
     * version segment. Admins who paste a URL ending in `/v1` (mirror
     * of what the protocol doc shows in examples) get double-`/v1/v1/`
     * 404s. Strip trailing slashes and a trailing `/v1` and warn so
     * the auto-correction is visible in the TYPO3 log.
     */
    private function normalizeServiceDbUrl(string $url): string
    {
        $stripped = rtrim($url, '/');
        if (str_ends_with($stripped, '/v1')) {
            $corrected = substr($stripped, 0, -3);
            $this->logger->warning(
                'simplecmp.serviceDbUrl ended in "/v1"; auto-stripping — '
                . 'the JS client appends the protocol-version segment itself. '
                . 'Configured: {configured}, sent to JS: {corrected}.',
                ['configured' => $url, 'corrected' => $corrected],
            );
            return $corrected;
        }
        return $stripped;
    }

    /**
     * Map the protocol-shaped rows from `ServiceRepository` to the runtime
     * `Service` shape the JS `init()` consumes, plus a `translations` block
     * carrying per-service title/description for the UI's lookup at
     * `services.<service_id>.{title,description}`.
     *
     * Title precedence:
     * - per-language: `i18n.title.<lang>` from the DB row (rarely set)
     * - fallback (`zz` language): the DB `name` column — the canonical
     *   display name, language-neutral if no localizations exist.
     *
     * Description precedence is the same: `i18n.description.<lang>` →
     * `zz` fallback from the DB `description` column.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function buildRuntimeServices(): array
    {
        $rows = $this->serviceRepository->paginate(0, 1000)['items'];
        $services = [];
        $translations = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $service = [
                // `name` is the consent key the manager tracks state under.
                'name' => $id,
                'purposes' => $row['purposes'] ?? [],
            ];
            $cookies = $row['matches']['cookies'] ?? [];
            if ($cookies !== []) {
                $service['cookies'] = $cookies;
            }
            if (isset($row['privacyPolicyUrl'])) {
                $service['privacyPolicyUrl'] = (string) $row['privacyPolicyUrl'];
            }
            if (isset($row['vendor'])) {
                $service['vendor'] = (string) $row['vendor'];
            }
            if (isset($row['vendorCountry'])) {
                $service['vendorCountry'] = (string) $row['vendorCountry'];
            }
            $services[] = $service;

            $translations['zz'][$id]['title'] = (string) $row['name'];
            if (isset($row['description'])) {
                $translations['zz'][$id]['description'] = (string) $row['description'];
            }
            $i18n = is_array($row['i18n'] ?? null) ? $row['i18n'] : [];
            foreach (['title', 'description'] as $field) {
                $perLang = $i18n[$field] ?? null;
                if (!is_array($perLang)) {
                    continue;
                }
                foreach ($perLang as $lang => $value) {
                    if (!is_string($value) || $value === '') {
                        continue;
                    }
                    $translations[(string) $lang][$id][$field] = $value;
                }
            }
        }
        return [$services, $translations];
    }
}
