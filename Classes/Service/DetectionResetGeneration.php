<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Core\Registry;

/**
 * Per-source "report generation" — a monotonic counter the BE bumps when
 * it hard-deletes (purges) detections that should re-surface if the
 * tracker is still present on the site.
 *
 * The FE bridge stores the generation alongside each cross-session dedup
 * marker; when the injected generation (see RegisterAssets) is newer than
 * a marker's, the bridge treats it as a miss and re-POSTs. Without this,
 * a purged detection stays suppressed on already-reporting browsers for
 * the whole cross-session TTL (≈7 days) — the root of the
 * "accept-once → no re-detect" bug.
 *
 * Keyed by the detection `source` string, which is the same value the FE
 * uses as `storageName` and the bridge uses as its payload `source`
 * (`simplecmp.storageName` or `simplecmp-<siteIdentifier>` — see
 * {@see DiscoverSource} and RegisterAssets). Backed by `sys_registry`
 * (namespace `tx_t3simplecmp`), same store as {@see LibraryUpstreamStats}.
 */
final readonly class DetectionResetGeneration
{
    private const string NAMESPACE = 'tx_t3simplecmp';
    private const string KEY_PREFIX = 'reportGeneration.';

    public function __construct(
        private Registry $registry,
    ) {
    }

    /** Current generation for a source (0 if never bumped). */
    public function current(string $source): int
    {
        return (int) $this->registry->get(self::NAMESPACE, self::KEY_PREFIX . $source, 0);
    }

    /** Bump the generation for a source so the FE re-reports its detections. */
    public function bump(string $source): void
    {
        if ($source === '') {
            return;
        }
        $this->registry->set(self::NAMESPACE, self::KEY_PREFIX . $source, $this->current($source) + 1);
    }
}
