<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use SimpleCMP\T3SimpleCmp\Service\ComplianceCheckService;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * A required service is consented for every visitor without asking; it must
 * not carry a tracking purpose (and with it a Consent Mode signal).
 */
final class ComplianceCheckServiceRequiredServicesTest extends TestCase
{
    #[Test]
    public function requiredFunctionalServicePasses(): void
    {
        $finding = $this->finding([
            ['id' => 'kb-router', 'name' => 'KB-Router', 'purposes' => ['functional'], 'required' => true],
        ]);

        self::assertTrue($finding['passed']);
    }

    #[Test]
    public function requiredServiceWithTrackingPurposeFails(): void
    {
        $finding = $this->finding([
            ['id' => 'kb-router', 'name' => 'KB-Router', 'purposes' => ['functional'], 'required' => true],
            ['id' => 'pixel', 'name' => 'Pixel', 'purposes' => ['functional', 'marketing'], 'required' => true],
        ]);

        self::assertFalse($finding['passed']);
        self::assertSame('critical', $finding['severity'] ?? null);
    }

    #[Test]
    public function optionalTrackingServicePasses(): void
    {
        $finding = $this->finding([
            ['id' => 'ga', 'name' => 'GA', 'purposes' => ['analytics']],
        ]);

        self::assertTrue($finding['passed']);
    }

    /**
     * @param list<array<string, mixed>> $services
     * @return array<string, mixed>
     */
    private function finding(array $services): array
    {
        $serviceRepo = $this->createMock(ServiceRepository::class);
        $serviceRepo->method('findAll')->willReturn($services);
        $overrideRepo = $this->createMock(TranslationOverrideRepository::class);
        $overrideRepo->method('findBySite')->willReturn(null);
        $themeRepo = $this->createMock(ThemeRepository::class);
        $themeRepo->method('findBySite')->willReturn(null);
        $effectiveSettings = $this->createMock(\SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver::class);
        $effectiveSettings->method('get')->willReturnCallback(
            static fn (string $siteId, string $key, mixed $default = null) => $default
        );
        $draftWorkspace = $this->createMock(\SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService::class);
        $service = new ComplianceCheckService($serviceRepo, $overrideRepo, $themeRepo, $effectiveSettings, $draftWorkspace);

        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('default');
        $settings = $this->createMock(\TYPO3\CMS\Core\Site\Entity\SiteSettings::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null) => $default
        );
        $site->method('getSettings')->willReturn($settings);

        foreach ($service->audit($site) as $result) {
            if (($result['id'] ?? null) === 'required-services-essential') {
                return $result;
            }
        }
        self::fail('Check "required-services-essential" missing from audit() results.');
    }
}
