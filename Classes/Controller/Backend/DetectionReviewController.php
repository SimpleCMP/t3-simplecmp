<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;

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
        private readonly PageRenderer $pageRenderer,
        private readonly ServiceRepository $serviceRepository,
    ) {
    }

    public function listAction(bool $onlyUnreviewed = true): ResponseInterface
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')
            ->from(self::DETECTION_TABLE)
            ->orderBy('received_at', 'DESC')
            ->setMaxResults(self::LIST_LIMIT);
        if ($onlyUnreviewed) {
            $qb->where($qb->expr()->eq('reviewed', $qb->createNamedParameter(0)));
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();

        // Build per-row action URLs in PHP — Fluid's `f:uri.action` doesn't
        // produce properly-namespaced URLs in BE module context (it omits
        // the `tx_<ext>_<mod>[action]` argument), so we generate URIs via
        // the Extbase UriBuilder here where it has the request context.
        // Bake the current filter state into every per-row action URL plus
        // the bulk-delete form. The action handlers read it back from the
        // request and redirect to `list` with the same value, so the user
        // stays on whichever filter they were viewing.
        $filterArg = ['onlyUnreviewed' => $onlyUnreviewed ? 1 : 0];
        $rowsWithActions = [];
        foreach ($rows as $r) {
            $rowArgs = ['uid' => (int) $r['uid']] + $filterArg;
            $r['uri_show'] = $this->uri('show', $rowArgs);
            $r['uri_createService'] = $this->uri('createService', ['uid' => (int) $r['uid']]);
            $r['uri_markReviewed'] = $this->uri('markReviewed', $rowArgs);
            $r['uri_unmarkReviewed'] = $this->uri('unmarkReviewed', $rowArgs);
            $rowsWithActions[] = $r;
        }

        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'detections' => $rowsWithActions,
            'onlyUnreviewed' => $onlyUnreviewed,
            'totalCount' => $this->totalCount(),
            'unreviewedCount' => $this->unreviewedCount(),
            'uri_filterUnreviewed' => $this->uri('list', ['onlyUnreviewed' => 1]),
            'uri_filterAll' => $this->uri('list', ['onlyUnreviewed' => 0]),
            'uri_bulkDelete' => $this->uri('bulkDelete', $filterArg),
        ]);
        return $moduleTemplate->renderResponse('DetectionReview/List');
    }

    public function showAction(int $uid, bool $onlyUnreviewed = true): ResponseInterface
    {
        $row = $this->fetchOne($uid);
        if ($row === null) {
            return $this->redirectToList($onlyUnreviewed);
        }

        $payload = null;
        try {
            $payload = json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }

        $row['uri_createService'] = $this->uri('createService', ['uid' => (int) $row['uid']]);
        $row['uri_markReviewed'] = $this->uri('markReviewed', ['uid' => (int) $row['uid'], 'onlyUnreviewed' => $onlyUnreviewed ? 1 : 0]);
        $row['uri_unmarkReviewed'] = $this->uri('unmarkReviewed', ['uid' => (int) $row['uid'], 'onlyUnreviewed' => $onlyUnreviewed ? 1 : 0]);

        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'detection' => $row,
            'payload' => $payload,
            'payloadJson' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'uri_list' => $this->uri('list', ['onlyUnreviewed' => $onlyUnreviewed ? 1 : 0]),
        ]);
        return $moduleTemplate->renderResponse('DetectionReview/Show');
    }

    /**
     * Build an Extbase URI for an action on this controller, with the
     * proper `tx_<ext>_<mod>` argument namespace baked in.
     *
     * @param array<string, scalar> $arguments
     */
    private function uri(string $action, array $arguments = []): string
    {
        return (string) $this->uriBuilder
            ->reset()
            ->setRequest($this->request)
            ->uriFor($action, $arguments);
    }

    public function markReviewedAction(int $uid, bool $onlyUnreviewed = true): ResponseInterface
    {
        $this->setReviewed($uid, true);
        $this->addFlash('flash.markedReviewed');
        return $this->redirectToList($onlyUnreviewed);
    }

    public function unmarkReviewedAction(int $uid, bool $onlyUnreviewed = true): ResponseInterface
    {
        $this->setReviewed($uid, false);
        $this->addFlash('flash.unmarkedReviewed');
        return $this->redirectToList($onlyUnreviewed);
    }

    public function bulkDeleteAction(bool $onlyUnreviewed = true): ResponseInterface
    {
        $count = $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->delete(self::DETECTION_TABLE, ['reviewed' => 1]);
        $this->addFlash('flash.bulkDeleted', ContextualFeedbackSeverity::OK, ['count' => $count]);
        return $this->redirectToList($onlyUnreviewed);
    }

    private function redirectToList(bool $onlyUnreviewed): ResponseInterface
    {
        return $this->redirect('list', null, null, ['onlyUnreviewed' => $onlyUnreviewed ? 1 : 0]);
    }

    /**
     * Open the TYPO3 record-edit form for the service entry that covers
     * this detection — either an existing one (so the admin sees their
     * previously-saved custom values) or a fresh new-record form
     * pre-populated with the detection's cookie / origin / identifier.
     */
    public function createServiceAction(int $uid): ResponseInterface
    {
        $row = $this->fetchOne($uid);
        if ($row === null) {
            return $this->redirect('list');
        }

        $returnUrl = (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections');

        // If a service already covers this detection, open it for editing
        // instead of starting a fresh new-record form. Otherwise the admin
        // would see only the controller-derived pre-fill values and would
        // lose the visual cue that they had already curated this entry.
        $existingUid = $this->findExistingServiceUid($row);
        if ($existingUid !== null) {
            $editUrl = (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [self::SERVICE_TABLE => [$existingUid => 'edit']],
                'returnUrl' => $returnUrl,
            ]);
            $this->addFlash('flash.createServiceExisting');
            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', $editUrl);
        }

        $defaults = $this->buildServiceDefaults($row);
        $editUrl = (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::SERVICE_TABLE => [0 => 'new']],
            'defVals' => [self::SERVICE_TABLE => $defaults],
            'returnUrl' => $returnUrl,
        ]);

        $this->addFlash('flash.createServiceRedirect');
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $editUrl);
    }

    /**
     * If any service already matches the detection's cookie name or
     * origin, return that service's uid. When several services overlap
     * on the same matcher, pick the most recently *created* one — the
     * admin's most recent curation, which the freshly-created record
     * almost always is. (`tstamp` would also bump from merely opening
     * the edit form, so `crdate` is the more stable signal.)
     *
     * @param array<string, mixed> $detection
     */
    private function findExistingServiceUid(array $detection): ?int
    {
        $kind = (string) ($detection['kind'] ?? '');
        $identifier = (string) ($detection['identifier'] ?? '');
        $origin = isset($detection['origin']) ? (string) $detection['origin'] : '';
        $cookie = $kind === 'cookie' && $identifier !== '' ? $identifier : null;
        $originVal = $kind !== 'cookie' && $origin !== '' ? $origin : null;
        if ($cookie === null && $originVal === null) {
            return null;
        }
        $matches = $this->serviceRepository->lookup($cookie, $originVal);
        if ($matches === []) {
            return null;
        }
        // The repository returns protocol-shape rows keyed by `id`
        // (service_id), not uid. One DB hop to resolve uids + tstamps,
        // pick the most recent.
        $serviceIds = array_map(static fn (array $m) => (string) $m['id'], $matches);
        $qb = $this->connectionPool->getQueryBuilderForTable(self::SERVICE_TABLE);
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')
            ->from(self::SERVICE_TABLE)
            ->where($qb->expr()->in(
                'service_id',
                $qb->createNamedParameter($serviceIds, \TYPO3\CMS\Core\Database\Connection::PARAM_STR_ARRAY)
            ))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $uid === false ? null : (int) $uid;
    }

    /** @return array<string, mixed>|null */
    private function fetchOne(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        // BE-context QueryBuilder applies default restrictions (deleted, hidden,
        // workspace). Our records are pid=0 with no enable-columns, so the
        // restrictions return nothing — strip them.
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::DETECTION_TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
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
        $qb->getRestrictions()->removeAll();
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
        // CSP-safe replacement for the inline `onsubmit="confirm(...)"` on the
        // bulk-delete form: the listener honours `data-confirm-message` and
        // calls preventDefault if the user cancels.
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/ConfirmForm.js'
        );
        return $moduleTemplate;
    }

    private function addFlash(
        string $key,
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK,
        array $tokens = [],
    ): void {
        $message = $this->translate($key) ?? $key;
        foreach ($tokens as $token => $value) {
            $message = str_replace('{' . $token . '}', (string) $value, $message);
        }
        $this->addFlashMessage($message, '', $severity);
    }

    private function translate(string $key): ?string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang === null) {
            return null;
        }
        $translated = $lang->sL(
            'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_mod.xlf:' . $key
        );
        return $translated !== '' ? $translated : null;
    }
}
