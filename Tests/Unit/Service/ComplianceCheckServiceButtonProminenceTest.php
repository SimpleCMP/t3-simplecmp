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
 * Focused tests for {@see ComplianceCheckService::checkButtonEqualProminence()}
 * — the broader audit() pipeline has its own coverage; here we lock the
 * static-config equivalent of the bundle's runtime equal-prominence
 * heuristic (Accept/Decline/Configure must not differ in visual weight).
 */
final class ComplianceCheckServiceButtonProminenceTest extends TestCase
{
    #[Test]
    public function noThemeRowPasses(): void
    {
        $results = $this->audit(null);
        $finding = $this->findByCheckId($results, 'heuristic-button-equal-prominence');

        self::assertTrue($finding['passed'], 'Without a theme row there are no per-button tokens, so no unequal prominence can exist.');
    }

    #[Test]
    public function onlyUnsuspiciousTokensPasses(): void
    {
        $results = $this->audit(['position' => 'middle-center']);
        $finding = $this->findByCheckId($results, 'heuristic-button-equal-prominence');

        self::assertTrue($finding['passed'], 'Unrelated theme tokens must not trigger the equal-prominence check.');
    }

    /**
     * Regression: the early lock shortcut returned pass for locked
     * palettes, but per-button tokens are NOT part of SAFE_PALETTE and
     * therefore still hit the live site. The default lock made this
     * check blind.
     */
    #[Test]
    public function lockedPaletteWithPerButtonBackgroundStillFails(): void
    {
        $results = $this->audit([
            'colorPaletteLocked' => '1',
            'color-accept-bg' => '#183d46',
        ]);
        $finding = $this->findByCheckId($results, 'heuristic-button-equal-prominence');

        self::assertFalse($finding['passed'], 'Locked palettes still emit per-button background tokens.');
        self::assertSame('warning', $finding['severity']);
        self::assertSame(1, $finding['context']['count']);
        self::assertStringContainsString('color-accept-bg', $finding['context']['sample']);
    }

    #[Test]
    public function unlockedPaletteWithPerButtonBackgroundStillFails(): void
    {
        $results = $this->audit([
            'colorPaletteLocked' => '0',
            'color-accept-bg' => '#183d46',
        ]);
        $finding = $this->findByCheckId($results, 'heuristic-button-equal-prominence');

        self::assertFalse($finding['passed'], 'Unlocked palettes keep the original warning behavior.');
        self::assertSame('warning', $finding['severity']);
        self::assertSame(1, $finding['context']['count']);
        self::assertStringContainsString('color-accept-bg', $finding['context']['sample']);
    }

    #[Test]
    public function textColorAloneTriggersWarning(): void
    {
        $results = $this->audit([
            'color-accept-text' => '#ffffff',
        ]);
        $finding = $this->findByCheckId($results, 'heuristic-button-equal-prominence');

        self::assertFalse($finding['passed'], 'A deviating text color shifts visual weight just like a background.');
        self::assertSame('warning', $finding['severity']);
        self::assertSame(1, $finding['context']['count']);
        self::assertStringContainsString('color-accept-text', $finding['context']['sample']);
    }

    #[Test]
    public function multipleOverridesAreCounted(): void
    {
        $results = $this->audit([
            'color-accept-bg' => '#183d46',
            'color-accept-text' => '#ffffff',
        ]);
        $finding = $this->findByCheckId($results, 'heuristic-button-equal-prominence');

        self::assertFalse($finding['passed']);
        self::assertSame(2, $finding['context']['count']);
    }

    /**
     * @param array<string, mixed>|null $tokens
     * @return list<array<string, mixed>>
     */
    private function audit(?array $tokens): array
    {
        $serviceRepo = $this->createMock(ServiceRepository::class);
        $serviceRepo->method('findAll')->willReturn([]);
        $overrideRepo = $this->createMock(TranslationOverrideRepository::class);
        $overrideRepo->method('findBySite')->willReturn(null);
        $themeRepo = $this->createMock(ThemeRepository::class);
        $themeRepo->method('findBySite')->willReturn($tokens);

        $effectiveSettings = $this->createMock(\SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver::class);
        $effectiveSettings->method('get')->willReturnCallback(
            static fn (string $siteId, string $key, mixed $default = null) => $default
        );
        // preferDraft defaults to false in audit(), so the workspace is
        // never consulted here — a bare mock keeps the constructor happy.
        $draftWorkspace = $this->createMock(\SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService::class);
        $service = new ComplianceCheckService($serviceRepo, $overrideRepo, $themeRepo, $effectiveSettings, $draftWorkspace);
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('default');
        // The check we exercise only consults the theme repo. The
        // broader audit() walks Site Settings too, so feed it an empty
        // settings object — `get()` returns the supplied defaults and
        // the other checks pass/fail uniformly without polluting this
        // file's results.
        $settings = $this->createMock(\TYPO3\CMS\Core\Site\Entity\SiteSettings::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null) => $default
        );
        $site->method('getSettings')->willReturn($settings);

        return $service->audit($site);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function findByCheckId(array $results, string $id): array
    {
        foreach ($results as $result) {
            if (($result['id'] ?? null) === $id) {
                return $result;
            }
        }
        self::fail(sprintf('Check id "%s" missing from audit() results.', $id));
    }
}
