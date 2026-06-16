<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Log\LoggerInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ConfigSnapshotRepository;

/**
 * Orchestrates {@see ConfigSnapshotResolver} +
 * {@see CanonicalJsonEncoder} + {@see ConfigSnapshotRepository}
 * into a single one-shot snapshot operation.
 *
 * Called from:
 *   - The DataHandler hook on service/theme/translation-override
 *     saves ({@see \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\SnapshotConfigOnSave}).
 *   - The CLI command for YAML-only edits / pre-deployment snapshots
 *     ({@see \SimpleCMP\T3SimpleCmp\Command\SnapshotConfigCommand}).
 *
 * Dedup via the `(site, version_hash)` UNIQUE constraint: identical
 * canonical JSON → same hash → existing-row check returns true → no
 * INSERT. The actual hash is returned in all cases so callers can log
 * "snapshot X is now the current state" without caring whether the
 * row is fresh or pre-existing.
 */
final readonly class ConfigSnapshotListener
{
    public function __construct(
        private ConfigSnapshotResolver $resolver,
        private CanonicalJsonEncoder $encoder,
        private ConfigSnapshotRepository $repository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve + encode + (possibly) INSERT. Returns the version hash
     * regardless of whether a new row was written. Returns null for
     * unknown sites — the resolver short-circuits silently in that
     * case (no exception, no snapshot).
     */
    public function snapshotIfChanged(
        string $siteIdentifier,
        string $triggerEvent,
        int $creatorBeUser = 0,
    ): ?string {
        $snapshot = $this->resolver->resolveCurrentSnapshot($siteIdentifier);
        if ($snapshot === null) {
            return null;
        }
        $canonical = $this->encoder->encode($snapshot);
        $hash = hash('sha256', $canonical);

        if ($this->repository->existsForHash($siteIdentifier, $hash)) {
            // Same content as a previous snapshot for this site — no
            // INSERT. We still return the hash so the caller can log
            // "current version is X" cleanly.
            $this->logger->debug(
                'ConfigSnapshot dedup: site={site} hash={hash} trigger={trigger}',
                ['site' => $siteIdentifier, 'hash' => substr($hash, 0, 12), 'trigger' => $triggerEvent],
            );
            return $hash;
        }

        $this->repository->insert(
            $siteIdentifier,
            $hash,
            $canonical,
            $triggerEvent,
            $creatorBeUser,
        );
        $this->logger->info(
            'ConfigSnapshot inserted: site={site} hash={hash} trigger={trigger} by={beUser}',
            [
                'site' => $siteIdentifier,
                'hash' => substr($hash, 0, 12),
                'trigger' => $triggerEvent,
                'beUser' => $creatorBeUser,
            ],
        );
        return $hash;
    }
}
