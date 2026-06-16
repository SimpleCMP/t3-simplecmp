<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller;

use Psr\Http\Message\ResponseInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRegistry;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Stage-2 BE wizard for the YAML-tracker-provisioning feature.
 *
 * Pairs the file-based `simplecmp.trackers` site-setting with a
 * database-backed alternative editors can manage through the BE UI
 * without touching settings.yaml. Both sources feed the same
 * `TrackerMaterializer` and therefore produce identical FE artefacts
 * (Service-DB row, loader script with `data-name`, bootstrap inline).
 *
 * The list view shows both sources side by side — YAML rows read-only
 * as a transparency surface, DB rows editable. New trackers added
 * via this wizard are scoped to the picked site (`tx_t3simplecmp_managed_tracker.site`).
 */
final class TrackerSetupController extends ActionController
{
    /**
     * Composer/Site-Set identifier of this extension. Sites without
     * this set in their resolved dependencies don't actually run
     * SimpleCMP on the FE — picking trackers for them would be a
     * no-op, so they're filtered out of the site selector.
     */
    private const string SET_IDENTIFIER = 'simplecmp/t3-simplecmp';

    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly ManagedTrackerRepository $managedTrackerRepository,
        private readonly TrackerRegistry $trackerRegistry,
    ) {
    }

    public function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setTitle($this->translate('module.trackerSetup.title'));
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    public function listAction(?string $site = null): ResponseInterface
    {
        $sites = $this->collectSites();
        if ($sites === []) {
            $this->moduleTemplate->assign('hasSites', false);
            return $this->moduleTemplate->renderResponse('TrackerSetup/List');
        }

        $selected = $this->resolveSelectedSite($site, $sites);

        $yamlTrackers = $this->collectYamlTrackers($selected);
        $dbTrackers = $this->managedTrackerRepository->findBySite($selected);

        $providerOptions = [];
        foreach ($this->trackerRegistry->getKnownTypes() as $type) {
            $providerOptions[$type] = $this->translate('module.trackerSetup.providerLabel.' . $type) ?: ucfirst($type);
        }

        // Pre-build per-site URLs so the template's site-picker can
        // drive a `<select onchange="window.location.assign(...)">`
        // instead of an Extbase `<f:form method="get">`. The form path
        // loses the BE-module route token on submission, which makes
        // TYPO3 render the response inside a nested module shell — the
        // backend navigation appears twice.
        $siteOptions = [];
        foreach ($sites as $entry) {
            $siteOptions[] = [
                'identifier' => $entry['identifier'],
                'label' => $entry['label'],
                'url' => (string) $this->backendUriBuilder->buildUriFromRoute(
                    'simplecmp_detections.TrackerSetup_list',
                    ['site' => $entry['identifier']],
                ),
            ];
        }

        $this->moduleTemplate->assignMultiple([
            'hasSites' => true,
            'sites' => $sites,
            'siteOptions' => $siteOptions,
            'selectedSite' => $selected,
            'yamlTrackers' => $yamlTrackers,
            'dbTrackers' => $this->enrichForRendering($dbTrackers),
            'providerOptions' => $providerOptions,
            // Sibling-tab URIs for the shared ModuleNav partial.
            'uri_detectionsTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.DetectionReview_list',
            ),
            'uri_registryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.RegistryList_list',
            ),
            'uri_libraryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.LibraryBrowser_list',
            ),
            'uri_trackerSetupTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.TrackerSetup_list',
            ),
        ]);

        return $this->moduleTemplate->renderResponse('TrackerSetup/List');
    }

    public function newAction(string $site, string $type): ResponseInterface
    {
        $provider = $this->trackerRegistry->get($type);
        if ($provider === null) {
            $this->addFlashMessage(
                sprintf($this->translate('module.trackerSetup.unknownType'), $type),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('list', null, null, ['site' => $site]);
        }

        $this->moduleTemplate->assignMultiple([
            'site' => $site,
            'type' => $type,
            'providerLabel' => $this->translate('module.trackerSetup.providerLabel.' . $type) ?: ucfirst($type),
            'fields' => $this->describeFieldsFor($type),
            'values' => [
                'serviceId' => $provider->getDefaultServiceId(),
            ],
            'isNew' => true,
        ]);
        return $this->moduleTemplate->renderResponse('TrackerSetup/Edit');
    }

    public function editAction(int $uid): ResponseInterface
    {
        $row = $this->managedTrackerRepository->findOne($uid);
        if ($row === null) {
            $this->addFlashMessage(
                $this->translate('module.trackerSetup.notFound'),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('list');
        }

        $values = $row['config'];
        $values['serviceId'] = $row['service_id'];

        $this->moduleTemplate->assignMultiple([
            'uid' => $uid,
            'site' => $row['site'],
            'type' => $row['tracker_type'],
            'providerLabel' => $this->translate('module.trackerSetup.providerLabel.' . $row['tracker_type']) ?: ucfirst($row['tracker_type']),
            'fields' => $this->describeFieldsFor($row['tracker_type']),
            'values' => $values,
            'isNew' => false,
        ]);
        return $this->moduleTemplate->renderResponse('TrackerSetup/Edit');
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveAction(int $uid, string $site, string $type, array $values): ResponseInterface
    {
        // The bare `site` param comes straight off the BE form/URL; refuse to
        // persist a managed-tracker row against a site that isn't a real
        // SimpleCMP-enabled site (data integrity — these rows are keyed by site).
        $validSites = array_column($this->collectSites(), 'identifier');
        if (!in_array($site, $validSites, true)) {
            $this->addFlashMessage(
                sprintf($this->translate('module.trackerSetup.unknownSite'), $site),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('list');
        }

        $provider = $this->trackerRegistry->get($type);
        if ($provider === null) {
            $this->addFlashMessage(
                sprintf($this->translate('module.trackerSetup.unknownType'), $type),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('list', null, null, ['site' => $site]);
        }

        $serviceId = trim((string) ($values['serviceId'] ?? '')) ?: $provider->getDefaultServiceId();
        unset($values['serviceId']);

        // Validate via the provider — buildServiceData throws on missing
        // required fields. We don't keep the result; we just want the
        // exception surface.
        try {
            $provider->buildServiceData([...$values, 'type' => $type, 'serviceId' => $serviceId]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlashMessage(
                $e->getMessage(),
                $this->translate('module.trackerSetup.validationFailed'),
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect($uid === 0 ? 'new' : 'edit', null, null, [
                'uid' => $uid,
                'site' => $site,
                'type' => $type,
            ]);
        }

        $this->managedTrackerRepository->save(
            $uid === 0 ? null : $uid,
            $site,
            $type,
            $serviceId,
            $this->normalizeValues($type, $values),
        );

        $this->addFlashMessage(
            $this->translate($uid === 0 ? 'module.trackerSetup.created' : 'module.trackerSetup.updated'),
            '',
            ContextualFeedbackSeverity::OK,
        );
        return $this->redirect('list', null, null, ['site' => $site]);
    }

    public function deleteAction(int $uid, string $site): ResponseInterface
    {
        $this->managedTrackerRepository->delete($uid);
        $this->addFlashMessage(
            $this->translate('module.trackerSetup.deleted'),
            '',
            ContextualFeedbackSeverity::OK,
        );
        return $this->redirect('list', null, null, ['site' => $site]);
    }

    // ----- helpers -----

    /**
     * Sites where SimpleCMP actually runs — i.e. have
     * `simplecmp/t3-simplecmp` in their resolved Site-Set dependencies.
     * Any other site can't render the consent UI, so showing trackers
     * for them in the picker would be misleading. Auto-generated
     * siteroot placeholders (which have empty Sets) drop out by the
     * same rule.
     *
     * @return list<array{identifier: string, label: string}>
     */
    private function collectSites(): array
    {
        $out = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (!in_array(self::SET_IDENTIFIER, $site->getSets(), true)) {
                continue;
            }
            $title = (string) ($site->getAttribute('websiteTitle') ?: $site->getIdentifier());
            $out[] = [
                'identifier' => $site->getIdentifier(),
                'label' => $title === $site->getIdentifier() ? $site->getIdentifier() : $title . ' (' . $site->getIdentifier() . ')',
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $out;
    }

    /**
     * @param list<array{identifier: string, label: string}> $sites
     */
    private function resolveSelectedSite(?string $requested, array $sites): string
    {
        $identifiers = array_column($sites, 'identifier');
        if ($requested !== null && in_array($requested, $identifiers, true)) {
            return $requested;
        }
        return $identifiers[0] ?? '';
    }

    /**
     * Pull YAML-configured trackers for the given site into the same
     * shape as DB rows, so the template can render both lists with
     * identical partials.
     *
     * @return list<array{type: string, serviceId: string, config: array<string, mixed>}>
     */
    private function collectYamlTrackers(string $siteIdentifier): array
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return [];
        }
        $settings = $site->getSettings();
        $flat = [];
        foreach ($settings->getIdentifiers() as $identifier) {
            if (str_starts_with($identifier, 'simplecmp.trackers.')) {
                $flat[$identifier] = $settings->get($identifier);
            }
        }
        if ($flat === []) {
            return [];
        }
        $tree = ArrayUtility::unflatten($flat);
        $list = $tree['simplecmp']['trackers'] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach (array_values($list) as $entry) {
            if (!is_array($entry) || !isset($entry['type']) || !is_string($entry['type'])) {
                continue;
            }
            $type = $entry['type'];
            $serviceId = (string) ($entry['serviceId'] ?? '');
            if ($serviceId === '') {
                $provider = $this->trackerRegistry->get($type);
                $serviceId = $provider?->getDefaultServiceId() ?? $type;
            }
            $config = $entry;
            unset($config['type'], $config['serviceId']);
            $out[] = ['type' => $type, 'serviceId' => $serviceId, 'config' => $config];
        }
        return $out;
    }

    /**
     * Per-provider field descriptors driving the edit form. Each
     * field carries the name (= keys the controller reads from
     * $values), a label, a kind hint (`text`, `number`, `bool`,
     * `url`, `enum`), and an optional required flag. `enum` fields
     * additionally carry an `options` list.
     *
     * Labels and help texts come from `locallang_mod.xlf` under the
     * `module.trackerSetup.field.<type>.<name>.{label,help}` keys, so
     * the BE language picker swaps them automatically. `enum` option
     * labels live under the same prefix plus `.<value>` segment.
     *
     * @return list<array{name: string, label: string, kind: string, required: bool, help: ?string, options?: list<array{value: string, label: string, help: ?string}>}>
     */
    private function describeFieldsFor(string $type): array
    {
        $shape = match ($type) {
            'matomo' => [
                ['name' => 'url', 'kind' => 'url', 'required' => true],
                ['name' => 'siteId', 'kind' => 'text', 'required' => true],
                ['name' => 'disableCookies', 'kind' => 'bool', 'required' => false],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            'ga4' => [
                ['name' => 'measurementId', 'kind' => 'text', 'required' => true],
                ['name' => 'anonymizeIp', 'kind' => 'bool', 'required' => false],
                [
                    'name' => 'consentPosture',
                    'kind' => 'enum',
                    'required' => false,
                    'options' => ['block', 'signal-gate'],
                ],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            'gtm' => [
                ['name' => 'containerId', 'kind' => 'text', 'required' => true],
                [
                    'name' => 'consentPosture',
                    'kind' => 'enum',
                    'required' => false,
                    'options' => ['block', 'signal-gate'],
                ],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            'meta' => [
                // Meta Pixel is signal-only — no loader URL, no
                // bootstrap snippet. The customer's own pixel template
                // continues to load fbevents.js; this row registers the
                // Service-DB metadata (banner listing, CSP origins,
                // _fbp/_fbc cookie classification) and tells the engine
                // to dispatch `fbq('consent', 'grant'|'revoke')` via the
                // ADR-0017 vendor adapter.
                ['name' => 'pixelId', 'kind' => 'text', 'required' => true],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            'microsoftUet' => [
                ['name' => 'tagId', 'kind' => 'text', 'required' => true],
                [
                    'name' => 'consentPosture',
                    'kind' => 'enum',
                    'required' => false,
                    'options' => ['block', 'signal-gate'],
                ],
                ['name' => 'serviceId', 'kind' => 'text', 'required' => false],
            ],
            default => [],
        };

        $out = [];
        foreach ($shape as $field) {
            $labelKey = 'module.trackerSetup.field.' . $type . '.' . $field['name'] . '.label';
            $helpKey = 'module.trackerSetup.field.' . $type . '.' . $field['name'] . '.help';
            $help = $this->translate($helpKey);
            $entry = [
                'name' => $field['name'],
                'kind' => $field['kind'],
                'required' => $field['required'],
                'label' => $this->translate($labelKey),
                'help' => $help !== '' ? $help : null,
            ];
            if ($field['kind'] === 'enum') {
                $entry['options'] = [];
                foreach ($field['options'] ?? [] as $value) {
                    $optionLabel = $this->translate(
                        'module.trackerSetup.field.' . $type . '.' . $field['name'] . '.option.' . $value . '.label',
                    );
                    $optionHelp = $this->translate(
                        'module.trackerSetup.field.' . $type . '.' . $field['name'] . '.option.' . $value . '.help',
                    );
                    $entry['options'][] = [
                        'value' => $value,
                        'label' => $optionLabel !== '' ? $optionLabel : $value,
                        'help' => $optionHelp !== '' ? $optionHelp : null,
                    ];
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeValues(string $type, array $values): array
    {
        $out = [];
        foreach ($this->describeFieldsFor($type) as $field) {
            $name = $field['name'];
            if ($name === 'serviceId') {
                continue;
            }
            $raw = $values[$name] ?? null;
            if ($field['kind'] === 'bool') {
                $out[$name] = $raw === '1' || $raw === 'on' || $raw === true;
                continue;
            }
            if ($field['kind'] === 'enum') {
                // Reject anything outside the declared option set so a
                // tampered POST can't smuggle a third posture into the
                // stored config. Default (omit the key) → provider falls
                // back to its safe default (`block` for consentPosture).
                $allowed = array_column($field['options'] ?? [], 'value');
                if (is_string($raw) && in_array($raw, $allowed, true)) {
                    $out[$name] = $raw;
                }
                continue;
            }
            if (is_string($raw)) {
                $trimmed = trim($raw);
                if ($trimmed !== '') {
                    $out[$name] = $trimmed;
                }
                continue;
            }
            if ($raw !== null && $raw !== '') {
                $out[$name] = $raw;
            }
        }
        return $out;
    }

    /**
     * @param list<array{uid: int, site: string, tracker_type: string, service_id: string, config: array<string, mixed>, tstamp: int, crdate: int}> $rows
     * @return list<array{uid: int, site: string, tracker_type: string, service_id: string, config: array<string, mixed>, providerLabel: string, summary: string}>
     */
    private function enrichForRendering(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $type = $row['tracker_type'];
            $providerLabel = $this->translate('module.trackerSetup.providerLabel.' . $type) ?: ucfirst($type);
            $summary = $this->buildSummary($type, $row['config']);
            $out[] = $row + [
                'providerLabel' => $providerLabel,
                'summary' => $summary,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildSummary(string $type, array $config): string
    {
        return match ($type) {
            'matomo' => sprintf('siteId %s @ %s', $config['siteId'] ?? '?', $config['url'] ?? '?'),
            'ga4' => (string) ($config['measurementId'] ?? '?'),
            'gtm' => (string) ($config['containerId'] ?? '?'),
            default => '',
        };
    }

    private function translate(string $key): string
    {
        if (str_starts_with($key, 'LLL:')) {
            return $this->getLanguageService()->sL($key);
        }
        return $this->getLanguageService()->sL('LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_mod.xlf:' . $key);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
