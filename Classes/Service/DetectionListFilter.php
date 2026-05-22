<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Applies SQL-expressible filters to a QueryBuilder for the
 * detection list view.
 *
 * Extracted from `DetectionReviewController::applyNonStateFilters`
 * so the filter logic can be functionally tested against a real
 * database without going through an Extbase request-dispatch
 * harness. The state filter (kuratiert / erkannt / unbekannt /
 * verworfen) is NOT applied here — it's derived per-row in PHP by
 * `DetectionListPresenter` because the matchers are JSON-encoded
 * arrays inside the service table and don't join cleanly.
 *
 * Filter keys handled (all optional, empty string = skip):
 *
 * - `source`   — `tx_simplecmptypo3_detection.source` exact match
 * - `kind`     — `tx_simplecmptypo3_detection.kind` exact match
 * - `confidence` — one of `low` (occurrences = 1),
 *                  `medium` (occurrences 2..4),
 *                  `high` (occurrences >= 5).
 *
 * Unknown confidence values are silently ignored. The caller is
 * responsible for normalising the filter array before passing it.
 */
final readonly class DetectionListFilter
{
    /**
     * @param array<string, string> $filters
     */
    public function apply(QueryBuilder $qb, array $filters): void
    {
        $source = (string) ($filters['source'] ?? '');
        if ($source !== '') {
            $qb->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)));
        }
        $kind = (string) ($filters['kind'] ?? '');
        if ($kind !== '') {
            $qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
        }
        $confidence = (string) ($filters['confidence'] ?? '');
        if ($confidence === 'low') {
            $qb->andWhere(
                $qb->expr()->eq('occurrences', $qb->createNamedParameter(1, ParameterType::INTEGER)),
            );
        } elseif ($confidence === 'medium') {
            // TYPO3's ExpressionBuilder doesn't expose `between()` —
            // express the range as two gte/lte conditions instead.
            // `andWhere()` ANDs multiple args together.
            $qb->andWhere(
                $qb->expr()->gte('occurrences', $qb->createNamedParameter(2, ParameterType::INTEGER)),
                $qb->expr()->lte('occurrences', $qb->createNamedParameter(4, ParameterType::INTEGER)),
            );
        } elseif ($confidence === 'high') {
            $qb->andWhere(
                $qb->expr()->gte('occurrences', $qb->createNamedParameter(5, ParameterType::INTEGER)),
            );
        }
    }
}
