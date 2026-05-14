<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend module: review unknown-tracker detections that the SimpleCMP
 * CMS bridge posted into `tx_simplecmptypo3_detection`.
 *
 * Five actions:
 * - `list`              — paginated, optionally filtered to unreviewed only
 * - `show`              — single-row detail with raw payload
 * - `markReviewed`      — flip the `reviewed` flag to 1
 * - `unmarkReviewed`    — flip back to 0
 * - `bulkDelete`        — wipe all detections currently marked reviewed
 * - `createService`     — redirect to TYPO3's record-edit form for a new
 *                         service, pre-filling cookie / origin matchers
 *                         from the detection (closes the loop)
 *
 * Extbase ActionController for the free Fluid + i18n + flash-message
 * infrastructure; the underlying queries are raw DBAL since the data
 * model is denormalized-JSON and Extbase ORM would buy us nothing.
 */
final class DetectionReviewController extends ActionController
{
    private const string DETECTION_TABLE = 'tx_simplecmptypo3_detection';
    private const string SERVICE_TABLE = 'tx_simplecmptypo3_service';
    private const int LIST_LIMIT = 200;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly UriBuilder $backendUriBuilder,
    ) {
    }

    public function listAction(bool $onlyUnreviewed = true): ResponseInterface
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->select('*')
            ->from(self::DETECTION_TABLE)
            ->orderBy('received_at', 'DESC')
            ->setMaxResults(self::LIST_LIMIT);
        if ($onlyUnreviewed) {
            $qb->where($qb->expr()->eq('reviewed', $qb->createNamedParameter(0)));
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'detections' => $rows,
            'onlyUnreviewed' => $onlyUnreviewed,
            'totalCount' => $this->totalCount(),
            'unreviewedCount' => $this->unreviewedCount(),
        ]);
        return $moduleTemplate->renderResponse('DetectionReview/List');
    }

    public function showAction(int $uid): ResponseInterface
    {
        $row = $this->fetchOne($uid);
        if ($row === null) {
            return $this->redirect('list');
        }

        $payload = null;
        try {
            $payload = json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }

        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'detection' => $row,
            'payload' => $payload,
            'payloadJson' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
        return $moduleTemplate->renderResponse('DetectionReview/Show');
    }

    public function markReviewedAction(int $uid): ResponseInterface
    {
        $this->setReviewed($uid, true);
        $this->addFlash('flash.markedReviewed');
        return $this->redirect('list');
    }

    public function unmarkReviewedAction(int $uid): ResponseInterface
    {
        $this->setReviewed($uid, false);
        $this->addFlash('flash.unmarkedReviewed');
        return $this->redirect('list');
    }

    public function bulkDeleteAction(): ResponseInterface
    {
        $count = $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->delete(self::DETECTION_TABLE, ['reviewed' => 1]);
        $this->addFlash('flash.bulkDeleted', AbstractMessage::OK, ['count' => $count]);
        return $this->redirect('list');
    }

    /**
     * Build the URL to the standard TYPO3 record-edit form for a NEW
     * service entry, pre-populated with the detection's cookie /
     * origin / identifier. The admin lands directly in the service form
     * with most fields already filled — they fill in name/purposes and
     * save.
     */
    public function createServiceAction(int $uid): ResponseInterface
    {
        $row = $this->fetchOne($uid);
        if ($row === null) {
            return $this->redirect('list');
        }

        $defaults = $this->buildServiceDefaults($row);
        $editUrl = (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::SERVICE_TABLE => [0 => 'new']],
            'defVals' => [self::SERVICE_TABLE => $defaults],
            'returnUrl' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections'),
        ]);

        $this->addFlash('flash.createServiceRedirect');
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $editUrl);
    }

    /** @return array<string, mixed>|null */
    private function fetchOne(int $uid): ?array
    {
        $row = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE)
            ->select('*')
            ->from(self::DETECTION_TABLE)
            ->where('uid = ' . $uid)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    private function setReviewed(int $uid, bool $reviewed): void
    {
        $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->update(
                self::DETECTION_TABLE,
                ['reviewed' => $reviewed ? 1 : 0, 'tstamp' => time()],
                ['uid' => $uid],
            );
    }

    private function totalCount(): int
    {
        return (int) $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->executeQuery('SELECT COUNT(*) FROM ' . self::DETECTION_TABLE)
            ->fetchOne();
    }

    private function unreviewedCount(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        return (int) $qb->count('*')
            ->from(self::DETECTION_TABLE)
            ->where($qb->expr()->eq('reviewed', $qb->createNamedParameter(0)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Pre-fill rules for the new-service form:
     * - service_id ← detection.identifier (lowercased, kebab-ified)
     * - name ← detection.identifier (verbatim; admin will edit)
     * - cookies ← `["<identifier>"]` for kind=cookie
     * - origins ← `["<origin>"]` for non-cookie kinds with origin set
     * - purposes ← `[]` (admin fills in)
     *
     * @param array<string, mixed> $detection
     * @return array<string, mixed>
     */
    private function buildServiceDefaults(array $detection): array
    {
        $kind = (string) ($detection['kind'] ?? '');
        $identifier = (string) ($detection['identifier'] ?? '');
        $origin = isset($detection['origin']) ? (string) $detection['origin'] : '';

        // Best-effort slug: lowercase, replace non-alnum with hyphen.
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $identifier) ?? '');
        $slug = trim($slug, '-') ?: 'unknown';

        $defaults = [
            'service_id' => $slug,
            'name' => $identifier,
            'purposes' => '[]',
        ];

        if ($kind === 'cookie') {
            $defaults['cookies'] = json_encode([$identifier], JSON_UNESCAPED_SLASHES);
        } elseif ($origin !== '') {
            $defaults['origins'] = json_encode([$origin], JSON_UNESCAPED_SLASHES);
        }

        return $defaults;
    }

    private function initModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SimpleCMP');
        return $moduleTemplate;
    }

    private function addFlash(string $key, int $severity = AbstractMessage::OK, array $tokens = []): void
    {
        $message = $this->translate($key) ?? $key;
        foreach ($tokens as $token => $value) {
            $message = str_replace('{' . $token . '}', (string) $value, $message);
        }
        $this->addFlashMessage($message, '', $severity);
    }

    private function translate(string $key): ?string
    {
        return \TYPO3\CMS\Core\Localization\LanguageService::create('default') === null
            ? null
            : ($GLOBALS['LANG']->sL(
                'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_mod.xlf:' . $key
            ) ?: null);
    }
}
