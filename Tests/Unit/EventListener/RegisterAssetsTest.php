<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\EventListener\RegisterAssets;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;

/**
 * Locks the asset-emission behaviour of the SimpleCMP frontend
 * listener: which combinations of site settings result in the bundle
 * + init() landing on the page, and which leave the page untouched.
 *
 * The bridge-secret gate gets explicit coverage: with `cmsBridgeUrl`
 * set and no secret configured, the listener must skip the bridge
 * config and log a warning rather than ship unauthenticated traffic.
 */
final class RegisterAssetsTest extends TestCase
{
    private AssetCollector&MockObject $assetCollector;
    private ServiceRepository&MockObject $services;
    private ThemeRepository&MockObject $themes;
    private BridgeSecretProvider&MockObject $secretProvider;
    private BridgeNonceService&MockObject $nonceService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->assetCollector = $this->createMock(AssetCollector::class);
        $this->services = $this->createMock(ServiceRepository::class);
        $this->services->method('paginate')->willReturn(['items' => [], 'total' => 0]);
        $this->themes = $this->createMock(ThemeRepository::class);
        // Default: no theme configured. Tests that need a theme override this.
        $this->themes->method('findBySite')->willReturn(null);
        $this->secretProvider = $this->createMock(BridgeSecretProvider::class);
        $this->nonceService = $this->createMock(BridgeNonceService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    #[Test]
    public function noAssetEmittedWhenNoRequestInGlobals(): void
    {
        $this->assetCollector->expects(self::never())->method('addJavaScript');
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    #[Test]
    public function noAssetEmittedForBackendRequest(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(
            applicationType: SystemEnvironmentBuilder::REQUESTTYPE_BE,
        );
        $this->assetCollector->expects(self::never())->method('addJavaScript');
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    #[Test]
    public function noAssetEmittedWhenSiteAttributeMissing(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(site: false);
        $this->assetCollector->expects(self::never())->method('addJavaScript');
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    #[Test]
    public function noAssetEmittedWhenSiteSetDisabled(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: ['simplecmp.enabled' => false]);
        $this->assetCollector->expects(self::never())->method('addJavaScript');
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    #[Test]
    public function noAssetEmittedWhenNeitherPrivacyNorServicesConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: []);
        $this->assetCollector->expects(self::never())->method('addJavaScript');
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    #[Test]
    public function emitsBundleAndInitWhenPrivacyUrlConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
        ]);
        $this->assetCollector->expects(self::once())
            ->method('addJavaScript')
            ->with(
                'simplecmp-bundle',
                self::stringContains('simplecmp.global.js'),
                self::anything(),
                self::anything(),
            );
        $captured = null;
        $this->assetCollector->expects(self::once())
            ->method('addInlineJavaScript')
            ->with(
                'simplecmp-init',
                self::callback(function (string $payload) use (&$captured): bool {
                    $captured = $payload;
                    return true;
                }),
                self::anything(),
                self::anything(),
            );
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('https://example.com/privacy', $config['privacyPolicy']);
        self::assertArrayNotHasKey('cmsBridgeUrl', $config);
        self::assertArrayNotHasKey('cmsBridgeAuth', $config);
    }

    #[Test]
    public function bundleAndInitRenderWithHeadPriority(): void
    {
        // Universal pre-consent blocking (ADR-0013 Phase 4) needs the
        // runtime patches installed BEFORE any inline body script can
        // dispatch third-party requests. Achieved by emitting the bundle
        // + init in the AssetCollector's "priority" (head) bucket. This
        // test locks the option flag so the regression has unit coverage.
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
        ]);
        $isPriority = static fn (mixed $options): bool =>
            is_array($options) && ($options['priority'] ?? false) === true;
        $this->assetCollector->expects(self::once())
            ->method('addJavaScript')
            ->with(
                'simplecmp-bundle',
                self::anything(),
                self::anything(),
                self::callback($isPriority),
            );
        $this->assetCollector->expects(self::once())
            ->method('addInlineJavaScript')
            ->with(
                'simplecmp-init',
                self::anything(),
                self::anything(),
                self::callback($isPriority),
            );

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    #[Test]
    public function bridgeConfigEmittedWhenSecretIsConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.cmsBridgeUrl' => 'https://example.com/api/simplecmp/webhook',
        ]);
        $this->secretProvider->method('isConfigured')->willReturn(true);
        $this->nonceService->expects(self::once())
            ->method('issue')
            ->with('simplecmp-default')
            ->willReturn('nonce-value');
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });
        $this->logger->expects(self::never())->method('warning');

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('https://example.com/api/simplecmp/webhook', $config['cmsBridgeUrl']);
        self::assertSame('nonce-value', $config['cmsBridgeAuth']['token']);
        self::assertSame(['silenceProductionWarning' => true], $config['record']);
    }

    #[Test]
    public function bridgeConfigSkippedAndWarnedWhenSecretMissing(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.cmsBridgeUrl' => 'https://example.com/api/simplecmp/webhook',
        ]);
        $this->secretProvider->method('isConfigured')->willReturn(false);
        $this->nonceService->expects(self::never())->method('issue');
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('bridgeSecret'));

        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertArrayNotHasKey('cmsBridgeUrl', $config);
        self::assertArrayNotHasKey('cmsBridgeAuth', $config);
    }

    #[Test]
    public function serviceDbUrlIsEmittedAndEnablesRecordMode(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.serviceDbUrl' => 'https://example.com/api/simplecmp',
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('https://example.com/api/simplecmp', $config['serviceDbUrl']);
        self::assertSame(['silenceProductionWarning' => true], $config['record']);
    }

    #[Test]
    public function serviceDbUrlTrailingSlashIsStripped(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.serviceDbUrl' => 'https://example.com/api/simplecmp/',
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('https://example.com/api/simplecmp', $config['serviceDbUrl']);
    }

    #[Test]
    public function serviceDbUrlTrailingV1IsStrippedAndWarningLogged(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.serviceDbUrl' => 'https://example.com/api/simplecmp/v1',
        ]);
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('/v1'),
                self::callback(fn (array $ctx): bool =>
                    ($ctx['configured'] ?? null) === 'https://example.com/api/simplecmp/v1'
                    && ($ctx['corrected'] ?? null) === 'https://example.com/api/simplecmp'
                ),
            );
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('https://example.com/api/simplecmp', $config['serviceDbUrl']);
    }

    #[Test]
    public function serviceDbUrlTrailingV1WithSlashIsStripped(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.serviceDbUrl' => 'https://example.com/api/simplecmp/v1/',
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('https://example.com/api/simplecmp', $config['serviceDbUrl']);
    }

    #[Test]
    public function imprintUrlIsEmittedWhenConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.imprintUrl' => 'https://example.com/imprint',
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('https://example.com/imprint', $config['imprint']);
    }

    #[Test]
    public function storageNameDefaultsToSimplecmpDashSiteIdentifier(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
        ], siteIdentifier: 'corporate');
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('simplecmp-corporate', $config['storageName']);
    }

    // --- universal blocking ------------------------------------------------

    #[Test]
    public function interceptRuntimeEmittedAsUniversalBlockObjectWhenEnabled(): void
    {
        // Closes the Phase 2 ↔ Phase 1 wiring gap AND lifts the
        // asymmetric-coverage gap (ADR-0013): the same site-setting
        // activates both the server-side rewriter and the FE patches
        // in the strict "block everything third-party" posture.
        // `universalBlock: true` widens the FE matcher to gate hosts
        // outside config.services[] too (using the host as synthetic
        // service id).
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.universalBlocking.enabled' => true,
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertIsArray($config['interceptRuntime']);
        self::assertTrue($config['interceptRuntime']['universalBlock']);
        // No allowlist configured → empty extras; window.location.host
        // is added implicitly by the runtime patches.
        self::assertSame([], $config['interceptRuntime']['sameOriginHosts']);
    }

    #[Test]
    public function interceptRuntimeForwardsAllowlistAsSameOriginHosts(): void
    {
        // The admin's `simplecmp.universalBlocking.allowlist` flows
        // through to the FE patches as `sameOriginHosts` so trusted
        // CDNs / vendor hosts pass through both layers without manual
        // FE config duplication. Empty / non-string entries are
        // filtered to match the HtmlRewriter's allowlist normalisation.
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.universalBlocking.enabled' => true,
            'simplecmp.universalBlocking.allowlist' => [
                'cdn.example.com',
                '*.vendor.example',
                '',  // skipped
            ],
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame(
            ['cdn.example.com', '*.vendor.example'],
            $config['interceptRuntime']['sameOriginHosts'],
        );
    }

    #[Test]
    public function interceptRuntimeAbsentWhenUniversalBlockingDisabled(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.universalBlocking.enabled' => false,
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertArrayNotHasKey('interceptRuntime', $config);
    }

    #[Test]
    public function interceptRuntimeDefaultsOnWhenUniversalBlockingUnset(): void
    {
        // Site Set default flipped to `true` in 93a4c9c. Sites that don't
        // explicitly set the key still get universalBlocking on — the
        // PHP-layer fallback in buildInitConfig mirrors the YAML default
        // so behavior stays consistent if the settings registry skips it.
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertArrayHasKey('interceptRuntime', $config);
        self::assertTrue($config['interceptRuntime']['universalBlock']);
    }

    // --- libraryFallback provider fields (REQ-19) -------------------------

    #[Test]
    public function libraryFallbackForwardsVendorFieldsFromCuratedEntries(): void
    {
        // The L2 Provider-Informationen modal (REQ-19) reads vendor /
        // vendorCountry / vendorAddress / vendorOptOutUrl /
        // vendorPartner / vendorDescription / privacyPolicyUrl from
        // each library entry. This test pins the forwarding wire-up:
        // any of the seven optional fields present on a library entry
        // must flow into the FE libraryFallback payload so the modal
        // can render them on state-2 (library-known) services.
        //
        // Asserts against `linkedin-insight` because it's present in
        // the ext-local services-library subset AND already has
        // `vendor`, `vendorCountry`, `privacyPolicyUrl` populated.
        // The remaining four fields will join when the dep is bumped
        // past v0.1.0 to include the Phase A.3 curated data.
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.universalBlocking.enabled' => true,
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertArrayHasKey('libraryFallback', $config);
        self::assertArrayHasKey('linkedin-insight', $config['libraryFallback']);
        $entry = $config['libraryFallback']['linkedin-insight'];
        self::assertSame('LinkedIn', $entry['vendor']);
        self::assertSame('IE', $entry['vendorCountry']);
        self::assertStringContainsString('LinkedIn Ireland Unlimited Company', $entry['vendorAddress']);
        self::assertSame('https://www.linkedin.com/psettings/advertising', $entry['vendorOptOutUrl']);
        self::assertStringContainsString('Microsoft Corporation', $entry['vendorPartner']);
        self::assertStringContainsString('EU establishment', $entry['vendorDescription']);
        self::assertSame('https://www.linkedin.com/legal/privacy-policy', $entry['privacyPolicyUrl']);
    }

    #[Test]
    public function libraryFallbackOmitsVendorFieldsForUncuratedEntries(): void
    {
        // Only ~32 of the ~369 library services have been curated with
        // vendor* fields. An uncurated entry should still appear in
        // libraryFallback when it has purposes (the existing behavior),
        // but WITHOUT the vendor* keys leaking through as empty strings
        // or null — we want absence to mean absence.
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.universalBlocking.enabled' => true,
        ]);
        $captured = null;
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $payload) use (&$captured): AssetCollector {
                $captured = $payload;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        $fallback = $config['libraryFallback'];
        // Find one library entry that has purposes but NOT vendor* fields.
        $uncuratedEntry = null;
        $uncuratedId = null;
        foreach ($fallback as $id => $entry) {
            if (
                !isset($entry['vendorAddress'])
                && !isset($entry['vendorPartner'])
                && isset($entry['purposes'])
            ) {
                $uncuratedEntry = $entry;
                $uncuratedId = $id;
                break;
            }
        }
        self::assertNotNull($uncuratedEntry, 'Expected at least one purposes-only library entry');
        self::assertArrayHasKey('purposes', $uncuratedEntry);
        foreach (
            ['vendorAddress', 'vendorOptOutUrl', 'vendorPartner', 'vendorDescription'] as $key
        ) {
            self::assertArrayNotHasKey(
                $key,
                $uncuratedEntry,
                sprintf('uncurated entry %s should not carry %s', $uncuratedId, $key),
            );
        }
    }

    // --- theme injection ---------------------------------------------------

    #[Test]
    public function noThemeScriptEmittedWhenNoThemeConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
        ]);
        $this->themes->method('findBySite')->willReturn(null);
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id) {
                self::assertStringNotContainsString('theme', $id);
                return $this->assetCollector;
            });
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    #[Test]
    public function themeScriptEmittedForSavedTheme(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request(
            settings: ['simplecmp.privacyPolicyUrl' => 'https://example.com/privacy'],
            siteIdentifier: 'corporate',
        );
        $this->themes = $this->createMock(ThemeRepository::class);
        $this->themes->method('findBySite')
            ->with('corporate')
            ->willReturn([
                'color-primary' => '#cc0066',
                'radius' => '12px',
            ]);

        $captured = [];
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $script) use (&$captured): AssetCollector {
                $captured[$id] = $script;
                return $this->assetCollector;
            });

        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        self::assertArrayHasKey('simplecmp-theme-corporate', $captured);
        $script = $captured['simplecmp-theme-corporate'];
        // Walks every SimpleCMP component type via adoptedStyleSheets —
        // a light-DOM <style> can't reach nested shadow roots, so the
        // script injects via JS instead.
        self::assertStringContainsString('simplecmp-banner', $script);
        self::assertStringContainsString('simplecmp-modal', $script);
        self::assertStringContainsString('simplecmp-purpose-group', $script);
        self::assertStringContainsString('simplecmp-service-toggle', $script);
        self::assertStringContainsString('adoptedStyleSheets', $script);
        // Storage key → CSS custom property name mapping.
        self::assertStringContainsString('--simplecmp-color-primary: #cc0066;', $script);
        self::assertStringContainsString('--simplecmp-radius: 12px;', $script);
        // Rule is scoped to :host so it lands inside the component shadow.
        self::assertStringContainsString(':host {', $script);
    }

    #[Test]
    public function emptyTokenArrayEmitsNoThemeScript(): void
    {
        // Edge case: a row may exist with an empty tokens array (admin
        // saved, then deleted every override). Treat that as "no theme".
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
        ]);
        $this->themes = $this->createMock(ThemeRepository::class);
        $this->themes->method('findBySite')->willReturn([]);
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id) {
                self::assertStringNotContainsString('theme', $id);
                return $this->assetCollector;
            });
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    #[Test]
    public function nonScalarTokenValuesAreFiltered(): void
    {
        // Defensive: a corrupt JSON blob in the DB shouldn't cause type
        // errors at FE render time. Non-scalar values get silently
        // dropped from the emitted script.
        $GLOBALS['TYPO3_REQUEST'] = $this->request(settings: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
        ]);
        $this->themes = $this->createMock(ThemeRepository::class);
        $this->themes->method('findBySite')->willReturn([
            'color-primary' => '#cc0066',
            'broken-nested' => ['not', 'scalar'],
        ]);

        $captured = [];
        $this->assetCollector->method('addInlineJavaScript')
            ->willReturnCallback(function (string $id, string $script) use (&$captured): AssetCollector {
                $captured[$id] = $script;
                return $this->assetCollector;
            });
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $script = $captured['simplecmp-theme-default'] ?? '';
        self::assertStringContainsString('--simplecmp-color-primary: #cc0066;', $script);
        self::assertStringNotContainsString('--simplecmp-broken-nested', $script);
    }

    // --- helpers -----------------------------------------------------------

    private function listener(): RegisterAssets
    {
        return new RegisterAssets(
            $this->assetCollector,
            $this->services,
            $this->themes,
            $this->secretProvider,
            $this->nonceService,
            $this->logger,
        );
    }

    /**
     * @param array<string, mixed>|null $settings — null leaves the request
     *   without a Site attribute (simulates pre-site-resolver middleware)
     */
    private function request(
        int $applicationType = SystemEnvironmentBuilder::REQUESTTYPE_FE,
        ?array $settings = [],
        string $siteIdentifier = 'default',
        bool $site = true,
    ): ServerRequestInterface {
        $req = $this->createMock(ServerRequestInterface::class);

        $resolvedSite = null;
        if ($settings !== null && $site) {
            $resolvedSite = $this->siteWithSettings($siteIdentifier, $settings);
        }

        $req->method('getAttribute')->willReturnCallback(
            static function (string $name, mixed $default = null) use ($applicationType, $resolvedSite): mixed {
                return match ($name) {
                    'applicationType' => $applicationType,
                    'site' => $resolvedSite,
                    default => $default,
                };
            }
        );
        return $req;
    }

    /** @param array<string, mixed> $values */
    private function siteWithSettings(string $identifier, array $values): Site
    {
        $settings = $this->createMock(SiteSettings::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key) => $values[$key] ?? null
        );
        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn($settings);
        $site->method('getIdentifier')->willReturn($identifier);
        return $site;
    }

    /** @return array<string, mixed> */
    private function extractConfig(?string $inlineScript): array
    {
        self::assertNotNull($inlineScript, 'Expected an inline JS payload to have been emitted');
        self::assertMatchesRegularExpression('/SimpleCMP\.init\(/', $inlineScript);
        $start = strpos($inlineScript, '(') + 1;
        $end = strrpos($inlineScript, ')');
        $json = substr($inlineScript, $start, $end - $start);
        $config = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        return $config;
    }
}
