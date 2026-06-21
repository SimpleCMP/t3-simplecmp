<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller;

use Psr\Http\Message\ResponseInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use SimpleCMP\T3SimpleCmp\Service\LockState;
use SimpleCMP\T3SimpleCmp\Service\SettingsDriftEntry;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Phase-5 BE-tab "Einstellungen" — YAML proposals + active overrides
 * for the editor-content settings.
 */
final class SettingsController extends ActionController
{
    private const string SET_IDENTIFIER = 'simplecmp/t3-simplecmp';

    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly EffectiveSettingsResolver $effectiveSettings,
        private readonly ManagedTrackerRepository $managedTrackers,
        private readonly \SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService $draftWorkspace,
        private readonly \SimpleCMP\T3SimpleCmp\Service\WizardBannerContext $wizardBannerContext,
    ) {
    }

    public function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setTitle($this->translate('module.settings.title'));
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    public function indexAction(string $site = ''): ResponseInterface
    {
        $sites = $this->collectSites();
        if ($sites === []) {
            $this->moduleTemplate->assign('hasSites', false);
            $this->assignTabUris($this->moduleTemplate);
            return $this->moduleTemplate->renderResponse('Settings/Index');
        }
        $site = $this->resolveSelectedSite($site, $sites);

        $drift = $this->effectiveSettings->drift($site);
        $isBootstrapped = $this->effectiveSettings->isBootstrapped($site);
        $proposals = $this->effectiveSettings->trackerProposals($site);

        // Fluid's strict template engine doesn't auto-invoke DTO
        // methods like `needsAction()` — pre-compute every flag the
        // template needs as a flat field. `effectiveValue` is what
        // the resolver actually returns (active wins, otherwise YAML)
        // so the "Live (aktiv)" column shows what visitors see, not
        // a misleading raw-DB null. `isCustom` is true only when the
        // editor has set an opinion that differs from YAML — that's
        // the only state where Reset is meaningful.
        $driftDecorated = array_map(
            static function ($entry): array {
                $hasActiveOpinion = $entry->activeValue !== null
                    || $entry->state === SettingsDriftEntry::STATE_DRIFT_CUSTOM
                    || $entry->state === SettingsDriftEntry::STATE_DRIFT_YAML_NEWER;
                // For "in-sync", "in-sync" means active==yaml OR no opinion → fallback.
                // Either way, effective = activeValue when set, else yamlValue.
                $effective = $hasActiveOpinion ? $entry->activeValue : $entry->yamlValue;
                $isCustom = $hasActiveOpinion
                    && !self::valuesEqualForDisplay($entry->activeValue, $entry->yamlValue);
                $refValue = $entry->yamlValue ?? $entry->activeValue;
                $type = match(true) {
                    is_bool($refValue) => 'bool',
                    is_array($refValue) => 'array',
                    default => 'string',
                };
                $effectiveForInput = match($type) {
                    'bool' => ($effective ? 'true' : 'false'),
                    'array' => json_encode($effective ?? [], JSON_PRETTY_PRINT),
                    default => (string) ($effective ?? ''),
                };
                return [
                    'key' => $entry->key,
                    'activeValue' => $entry->activeValue,
                    'yamlValue' => $entry->yamlValue,
                    'effectiveValue' => $effective,
                    'state' => $entry->state,
                    'needsAction' => $entry->needsAction(),
                    'isCustom' => $isCustom,
                    'isFallback' => !$hasActiveOpinion,
                    'type' => $type,
                    'effectiveValueForInput' => $effectiveForInput,
                ];
            },
            $drift,
        );
        $actionableDrift = array_values(array_filter(
            $driftDecorated,
            static fn (array $e) => $e['needsAction'] === true,
        ));
        $proposalsDecorated = array_map(
            static fn ($p) => [
                'type' => $p->type,
                'serviceId' => $p->serviceId,
                'config' => $p->config,
                'alreadyAdopted' => $p->alreadyAdopted,
            ],
            $proposals,
        );

        $this->moduleTemplate->assignMultiple($this->wizardBannerContext->forSite($site) + [
            'hasSites' => true,
            'sites' => $sites,
            'selectedSite' => $site,
            'siteOptions' => $this->siteOptions($sites),
            'drift' => $driftDecorated,
            'actionableDrift' => $actionableDrift,
            'isBootstrapped' => $isBootstrapped,
            'driftCount' => count($actionableDrift),
            'trackerProposals' => $proposalsDecorated,
            'unadoptedTrackerCount' => array_reduce(
                $proposalsDecorated,
                static fn (int $c, array $p) => $c + ($p['alreadyAdopted'] ? 0 : 1),
                0,
            ),
            'editorKeys' => EffectiveSettingsResolver::EDITOR_CONTENT_KEYS,
            'uri_wizardReopen' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.SetupWizard_reopen',
                ['site' => $site],
            ),
        ]);
        $this->assignTabUris($this->moduleTemplate);
        return $this->moduleTemplate->renderResponse('Settings/Index');
    }

    /**
     * Initial-Übernahme: alle YAML-Werte als Active für die Site
     * speichern. Markiert sie als „Editor hat Verantwortung
     * übernommen".
     */
    public function bootstrapAction(string $site): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0 || !$this->siteIsKnown($site)) {
            return $this->redirect('index', null, null, ['site' => $site]);
        }
        $this->effectiveSettings->adoptAll($site, $beUserId);
        $this->addFlashMessage(
            sprintf($this->translate('module.settings.bootstrap.success'), $site),
            '',
            ContextualFeedbackSeverity::OK,
        );
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    public function adoptKeyAction(string $site, string $key): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        try {
            $this->effectiveSettings->adoptKey($site, $key, $beUserId);
            $this->addFlashMessage(
                sprintf($this->translate('module.settings.adopt.success'), $key),
                '',
                ContextualFeedbackSeverity::OK,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlashMessage($e->getMessage(), '', ContextualFeedbackSeverity::ERROR);
        }
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    public function adoptAllAction(string $site): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        $this->effectiveSettings->adoptAll($site, $beUserId);
        $this->addFlashMessage(
            $this->translate('module.settings.adopt.allSuccess'),
            '',
            ContextualFeedbackSeverity::OK,
        );
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    public function setCustomAction(string $site, string $key, string $value): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        try {
            // Try JSON decode first — for bool/int/array values the
            // form submits a JSON-encoded string. Falls back to raw
            // string for normal string keys.
            $decoded = json_decode($value, true);
            $resolved = $decoded ?? $value;
            $this->effectiveSettings->setCustom($site, $key, $resolved, $beUserId);
            $this->addFlashMessage(
                sprintf($this->translate('module.settings.custom.success'), $key),
                '',
                ContextualFeedbackSeverity::OK,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlashMessage($e->getMessage(), '', ContextualFeedbackSeverity::ERROR);
        }
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    public function resetKeyAction(string $site, string $key): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        $this->effectiveSettings->resetToYaml($site, $key, $beUserId);
        $this->addFlashMessage(
            sprintf($this->translate('module.settings.reset.success'), $key),
            '',
            ContextualFeedbackSeverity::OK,
        );
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    /**
     * Tracker-Vorschlag annehmen → als managed_tracker-Draft anlegen.
     * Editor sieht den Tracker anschließend im Tracker-Setup-Tab als
     * Draft + kann ihn dort fein editieren + per Veröffentlichen
     * promoten.
     */
    public function adoptTrackerAction(string $site, string $type, string $serviceId): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0 || !$this->siteIsKnown($site)) {
            return $this->redirect('index', null, null, ['site' => $site]);
        }

        // Find the YAML proposal matching this type+serviceId so we
        // copy its config.
        $proposal = null;
        foreach ($this->effectiveSettings->trackerProposals($site) as $p) {
            if ($p->type === $type && $p->serviceId === $serviceId) {
                $proposal = $p;
                break;
            }
        }
        if ($proposal === null) {
            $this->addFlashMessage(
                'Tracker-Vorschlag nicht gefunden.',
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('index', null, null, ['site' => $site]);
        }

        // Acquire the per-site draft lock and write a managed_tracker
        // draft row using the proposal's config.
        $lock = $this->draftWorkspace->initializeDraft($site, $beUserId);
        if ($lock->conflict) {
            $this->addFlashMessage(
                sprintf('Lock für Site "%s" gehört BE-User uid=%d.', $site, $lock->ownerBeUserId),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('index', null, null, ['site' => $site]);
        }
        $this->managedTrackers->saveDraft(
            $site,
            null,
            $site,
            $type,
            $serviceId,
            $proposal->config,
            $beUserId,
        );
        $this->addFlashMessage(
            sprintf(
                $this->translate('module.settings.tracker.adopted'),
                $type,
                $serviceId,
            ),
            '',
            ContextualFeedbackSeverity::OK,
        );
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Lightweight equality check for the "is custom override?" flag.
     * Same semantics as `EffectiveSettingsResolver::valuesEqual` but
     * inlined here so we can compute it without re-running drift().
     */
    private static function valuesEqualForDisplay(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            return json_encode($a) === json_encode($b);
        }
        if (is_array($a) || is_array($b)) {
            return false;
        }
        return $a === $b
            || ($a !== null && $b !== null && is_scalar($a) && is_scalar($b) && (string) $a === (string) $b);
    }

    private function currentBeUserId(): int
    {
        return (int) ($GLOBALS['BE_USER']->user['uid'] ?? 0);
    }

    /**
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
                'label' => $title === $site->getIdentifier() ? $title : $title . ' (' . $site->getIdentifier() . ')',
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $out;
    }

    private function siteIsKnown(string $site): bool
    {
        foreach ($this->collectSites() as $e) {
            if ($e['identifier'] === $site) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{identifier: string, label: string}> $sites
     */
    private function resolveSelectedSite(string $requested, array $sites): string
    {
        $identifiers = array_column($sites, 'identifier');
        if ($requested !== '' && in_array($requested, $identifiers, true)) {
            return $requested;
        }
        return $identifiers[0] ?? '';
    }

    /**
     * @param list<array{identifier: string, label: string}> $sites
     * @return list<array{identifier: string, label: string, url: string}>
     */
    private function siteOptions(array $sites): array
    {
        $out = [];
        foreach ($sites as $entry) {
            $out[] = [
                'identifier' => $entry['identifier'],
                'label' => $entry['label'],
                'url' => (string) $this->backendUriBuilder->buildUriFromRoute(
                    'simplecmp_detections.Settings_index',
                    ['site' => $entry['identifier']],
                ),
            ];
        }
        return $out;
    }

    private function assignTabUris(ModuleTemplate $template): void
    {
        $template->assignMultiple([
            'uri_detectionsTab' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections.DetectionReview_list'),
            'uri_registryTab' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections.RegistryList_list'),
            'uri_libraryTab' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections.LibraryBrowser_list'),
            'uri_trackerSetupTab' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections.TrackerSetup_list'),
            'uri_auditTab' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections.AuditSnapshot_list'),
            'uri_auskunftTab' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections.AuditAuskunft_index'),
            'uri_settingsTab' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections.Settings_index'),
            'uri_designerTab' => (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections.ThemeDesigner_index'),
        ]);
    }

    private function translate(string $key): string
    {
        return (string) ($this->getLanguageService()->sL(
            'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_mod.xlf:' . $key,
        ) ?? '');
    }

    private function getLanguageService(): \TYPO3\CMS\Core\Localization\LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
