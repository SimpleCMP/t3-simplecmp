<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\AuditRetentionService;
use SimpleCMP\T3SimpleCmp\Service\RetentionRequest;

/**
 * Lock the threshold arithmetic + target enum. Both are
 * security-relevant (wrong threshold = wrong rows go; unknown target
 * = potential SQL exposure).
 */
final class RetentionRequestTest extends TestCase
{
    #[Test]
    public function thresholdSubtractsKeepDaysInSeconds(): void
    {
        $req = new RetentionRequest(
            target: AuditRetentionService::TARGET_CONSENT_LOG,
            keepDays: 30,
            reason: str_repeat('x', 30),
            invokedBy: 'cli',
            site: null,
            dryRun: false,
            now: 1_700_000_000,
        );
        // 30 days = 30 * 86400 = 2_592_000 seconds
        self::assertSame(1_700_000_000 - 2_592_000, $req->thresholdCrdate());
    }

    #[Test]
    public function thresholdMatchesNowForZeroKeepDays(): void
    {
        $req = new RetentionRequest(
            target: AuditRetentionService::TARGET_CONSENT_LOG,
            keepDays: 0,
            reason: str_repeat('x', 30),
            invokedBy: 'cli',
            site: null,
            dryRun: true,
            now: 1_700_000_000,
        );
        self::assertSame(1_700_000_000, $req->thresholdCrdate());
    }

    #[Test]
    public function availableTargetsExcludeTheRetentionLogItself(): void
    {
        $targets = AuditRetentionService::availableTargets();
        self::assertContains(AuditRetentionService::TARGET_CONFIG_SNAPSHOT, $targets);
        self::assertContains(AuditRetentionService::TARGET_CONSENT_LOG, $targets);
        // The retention log MUST NOT be in the enum — it is the log of deletions,
        // its own deletion would defeat the purpose.
        self::assertNotContains('audit-retention-log', $targets);
    }

    #[Test]
    public function tableForTargetMapsKnownEnum(): void
    {
        self::assertSame(
            'tx_t3simplecmp_config_snapshot',
            AuditRetentionService::tableForTarget(AuditRetentionService::TARGET_CONFIG_SNAPSHOT),
        );
        self::assertSame(
            'tx_t3simplecmp_consent_log',
            AuditRetentionService::tableForTarget(AuditRetentionService::TARGET_CONSENT_LOG),
        );
    }

    #[Test]
    public function tableForTargetThrowsOnUnknownTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 'all' is a CLI-layer convenience, NOT a single-table target —
        // it must NOT resolve to a table name via this API.
        AuditRetentionService::tableForTarget('all');
    }

    #[Test]
    public function tableForTargetThrowsOnArbitraryTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Defense against operator typo or injection — the table name
        // we'd actually run DELETE on can only come from this map.
        AuditRetentionService::tableForTarget('tx_t3simplecmp_service');
    }
}
