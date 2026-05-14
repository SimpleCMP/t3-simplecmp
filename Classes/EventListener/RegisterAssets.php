<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Site\Entity\Site;

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

        $config = [
            'storageName' => $get('simplecmp.storageName') ?: 'simplecmp-' . $site->getIdentifier(),
            'services' => [],
            'respectGPC' => (bool) $get('simplecmp.respectGPC', true),
            'floatingTrigger' => [
                'label' => (string) $get('simplecmp.floatingTriggerLabel', 'Cookie settings'),
            ],
        ];

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
            $config['serviceDbUrl'] = $serviceDbUrl;
            // The recorder must be running for the bridge to fire — opt
            // into record mode automatically when an admin has configured
            // a remote data sink (service DB or CMS bridge).
            $config['record'] = ['silenceProductionWarning' => true];
        }

        $cmsBridgeUrl = (string) $get('simplecmp.cmsBridgeUrl', '');
        if ($cmsBridgeUrl !== '') {
            $config['cmsBridgeUrl'] = $cmsBridgeUrl;
            $config['record'] = ['silenceProductionWarning' => true];
        }

        if ($privacy === '' && $config['services'] === []) {
            // Nothing useful to mount — skip the asset registration so
            // we don't ship dead JS on every page.
            return null;
        }

        return $config;
    }
}
