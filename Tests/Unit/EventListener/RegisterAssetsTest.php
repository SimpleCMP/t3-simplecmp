<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\EventListener;

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
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;
use WapplerSystems\SimpleCmpTypo3\EventListener\RegisterAssets;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeNonceService;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeSecretProvider;

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
    private BridgeSecretProvider&MockObject $secretProvider;
    private BridgeNonceService&MockObject $nonceService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->assetCollector = $this->createMock(AssetCollector::class);
        $this->services = $this->createMock(ServiceRepository::class);
        $this->services->method('paginate')->willReturn(['items' => [], 'total' => 0]);
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
            ->with('simplecmp-bundle', self::stringContains('simplecmp.global.js'));
        $captured = null;
        $this->assetCollector->expects(self::once())
            ->method('addInlineJavaScript')
            ->with('simplecmp-init', self::callback(function (string $payload) use (&$captured): bool {
                $captured = $payload;
                return true;
            }));
        $this->listener()(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));

        $config = $this->extractConfig($captured);
        self::assertSame('https://example.com/privacy', $config['privacyPolicy']);
        self::assertArrayNotHasKey('cmsBridgeUrl', $config);
        self::assertArrayNotHasKey('cmsBridgeAuth', $config);
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

    // --- helpers -----------------------------------------------------------

    private function listener(): RegisterAssets
    {
        return new RegisterAssets(
            $this->assetCollector,
            $this->services,
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
