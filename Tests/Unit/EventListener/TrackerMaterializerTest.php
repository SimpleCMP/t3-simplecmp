<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\EventListener\TrackerMaterializer;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\Tracker\GtmProvider;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRegistry;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRuntimeState;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * A managed tracker in `block` posture must not reach the page as a live
 * `<script src>`: the parser fetches and runs it before the engine can
 * intervene (the bundle's src-setter patches only see scripts inserted from
 * JavaScript). Measured on a production site: gtm.js loaded and `_gcl_au`
 * was set without any consent.
 */
final class TrackerMaterializerTest extends TestCase
{
    private AssetCollector&MockObject $assetCollector;

    protected function setUp(): void
    {
        $this->assetCollector = $this->createMock(AssetCollector::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    #[Test]
    public function blockPostureEmitsLoaderInGateShape(): void
    {
        $inline = [];
        $this->assetCollector->method('addInlineJavaScript')->willReturnCallback(
            function (string $id, string $source, array $attributes = []) use (&$inline): AssetCollector {
                $inline[$id] = ['source' => $source, 'attributes' => $attributes];
                return $this->assetCollector;
            }
        );
        $this->assetCollector->expects(self::never())->method('addJavaScript');

        $this->materialize(['containerId' => 'GTM-ABC123', 'consentPosture' => 'block']);

        self::assertArrayHasKey('simplecmp-tracker-loader-gtm', $inline);
        $loader = $inline['simplecmp-tracker-loader-gtm'];
        self::assertSame('', $loader['source']);
        self::assertSame('text/plain', $loader['attributes']['type'] ?? null);
        self::assertSame('gtm', $loader['attributes']['data-name'] ?? null);
        self::assertSame(
            'https://www.googletagmanager.com/gtm.js?id=GTM-ABC123',
            $loader['attributes']['data-src'] ?? null,
        );
        self::assertArrayNotHasKey('src', $loader['attributes']);
        self::assertSame('1', $loader['attributes']['data-no-placeholder'] ?? null);
    }

    #[Test]
    public function signalGatePostureKeepsTheLiveLoader(): void
    {
        $loaders = [];
        $this->assetCollector->method('addJavaScript')->willReturnCallback(
            function (string $id, string $source, array $attributes = []) use (&$loaders): AssetCollector {
                $loaders[$id] = ['source' => $source, 'attributes' => $attributes];
                return $this->assetCollector;
            }
        );

        $this->materialize(['containerId' => 'GTM-ABC123', 'consentPosture' => 'signal-gate']);

        self::assertSame(
            'https://www.googletagmanager.com/gtm.js?id=GTM-ABC123',
            $loaders['simplecmp-tracker-loader-gtm']['source'] ?? null,
        );
        self::assertArrayNotHasKey('data-name', $loaders['simplecmp-tracker-loader-gtm']['attributes']);
    }

    /** @param array<string, mixed> $config */
    private function materialize(array $config): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->frontendRequest();

        $managedTrackers = $this->createMock(ManagedTrackerRepository::class);
        $managedTrackers->method('findBySite')->willReturn([
            ['config' => $config, 'tracker_type' => 'gtm', 'service_id' => ''],
        ]);

        $listener = new TrackerMaterializer(
            $this->assetCollector,
            $this->createMock(ServiceRepository::class),
            $managedTrackers,
            new TrackerRegistry([new GtmProvider()]),
            new TrackerRuntimeState(),
            $this->createMock(StoragePidResolver::class),
            new NullLogger(),
        );
        $listener(new BeforeJavaScriptsRenderingEvent($this->assetCollector, false, false));
    }

    private function frontendRequest(): ServerRequestInterface
    {
        $settings = $this->createMock(SiteSettings::class);
        $settings->method('has')->willReturn(false);
        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn($settings);
        $site->method('getIdentifier')->willReturn('default');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => match ($name) {
                'applicationType' => SystemEnvironmentBuilder::REQUESTTYPE_FE,
                'site' => $site,
                default => $default,
            }
        );
        return $request;
    }
}
