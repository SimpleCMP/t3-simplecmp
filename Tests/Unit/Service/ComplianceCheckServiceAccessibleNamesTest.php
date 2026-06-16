<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use SimpleCMP\T3SimpleCmp\Service\ComplianceCheckService;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * REQ-N11 — `dom-accessible-names` check PHP mirror.
 *
 * Focused tests for the new {@see ComplianceCheckService::checkAccessibleNameOverrides()}
 * — the broader audit() pipeline has its own coverage; here we lock the
 * static-config equivalent of the bundle's runtime DOM check (every
 * banner action and the banner region must expose an accessible name).
 */
final class ComplianceCheckServiceAccessibleNamesTest extends TestCase
{
    #[Test]
    public function noOverridesPasses(): void
    {
        $results = $this->audit(null);
        $finding = $this->findByCheckId($results, 'dom-accessible-names');
        self::assertTrue($finding['passed'], 'Bundle defaults provide every label — no override means no risk.');
    }

    #[Test]
    public function emptyOverrideMapPasses(): void
    {
        $results = $this->audit([]);
        $finding = $this->findByCheckId($results, 'dom-accessible-names');
        self::assertTrue($finding['passed']);
    }

    #[Test]
    public function nonEmptyButValidOverridesPass(): void
    {
        $results = $this->audit([
            'de' => [
                'tone' => null,
                'overrides' => [
                    'acceptAll' => 'Alle annehmen',
                    'decline' => 'Ablehnen',
                    'consentNotice.title' => 'Cookie-Hinweis',
                ],
            ],
        ]);
        $finding = $this->findByCheckId($results, 'dom-accessible-names');
        self::assertTrue($finding['passed']);
    }

    /**
     * @return list<array{0: array<string, mixed>, 1: int, 2: string}>
     */
    public static function blankingCases(): array
    {
        return [
            'empty string in acceptAll' => [
                ['de' => ['overrides' => ['acceptAll' => '']]],
                1,
                'acceptAll',
            ],
            'whitespace-only string' => [
                ['de' => ['overrides' => ['decline' => "   \t\n"]]],
                1,
                'decline',
            ],
            'null value' => [
                ['de' => ['overrides' => ['consentNotice.title' => null]]],
                1,
                'consentNotice.title',
            ],
            'two blanks in one language' => [
                ['de' => ['overrides' => ['acceptAll' => '', 'decline' => '']]],
                2,
                'acceptAll',
            ],
            'same key blank in two languages' => [
                [
                    'de' => ['overrides' => ['acceptAll' => '']],
                    'en' => ['overrides' => ['acceptAll' => '']],
                ],
                2,
                'acceptAll',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[Test]
    #[DataProvider('blankingCases')]
    public function blankOverridesFailWithCriticalSeverity(array $overrides, int $expectedCount, string $sampleKeyMustAppear): void
    {
        $results = $this->audit($overrides);
        $finding = $this->findByCheckId($results, 'dom-accessible-names');
        self::assertFalse($finding['passed']);
        self::assertSame('critical', $finding['severity']);
        self::assertSame($expectedCount, $finding['context']['count']);
        self::assertStringContainsString($sampleKeyMustAppear, (string) $finding['context']['sample']);
    }

    #[Test]
    public function blanksOnNonAccessibilityKeysAreIgnored(): void
    {
        // `purposes.functional.title` isn't on the accessible-name list —
        // an editor blanking it is a separate concern (caught by the
        // broader description-length / weak-decline heuristics, or no
        // check at all). The accessible-names check stays narrow.
        $results = $this->audit([
            'de' => [
                'overrides' => [
                    'purposes.functional.title' => '',
                    'someUnrelatedKey' => '',
                ],
            ],
        ]);
        $finding = $this->findByCheckId($results, 'dom-accessible-names');
        self::assertTrue($finding['passed']);
    }

    #[Test]
    public function reportsCheckIdMatchingBundle(): void
    {
        // ID must match the bundle's upstream `src/audit/dom.ts`
        // `checkAccessibleNames` id verbatim — drift here breaks the
        // cross-cutting "PHP says pass, FE audit says fail" reconciliation.
        $finding = $this->findByCheckId($this->audit(null), 'dom-accessible-names');
        self::assertSame('dom-accessible-names', $finding['id']);
        self::assertSame('2.2', $finding['section']);
    }

    /**
     * @param array<string, mixed>|null $overrides
     * @return list<array<string, mixed>>
     */
    private function audit(?array $overrides): array
    {
        $serviceRepo = $this->createMock(ServiceRepository::class);
        $serviceRepo->method('findAll')->willReturn([]);
        $overrideRepo = $this->createMock(TranslationOverrideRepository::class);
        $overrideRepo->method('findBySite')->willReturn($overrides);
        $themeRepo = $this->createMock(ThemeRepository::class);
        $themeRepo->method('findBySite')->willReturn(null);

        $service = new ComplianceCheckService($serviceRepo, $overrideRepo, $themeRepo);
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('default');
        // The check we exercise only consults the override repo. The
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
