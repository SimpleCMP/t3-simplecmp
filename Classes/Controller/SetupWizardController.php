<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller;

use Psr\Http\Message\ResponseInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Service\DraftPublishService;
use SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use SimpleCMP\T3SimpleCmp\Service\WizardStateService;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRegistry;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Linear 3-step onboarding wizard. Wraps the Phase-4 draft and
 * Phase-5 active-settings stack so a first-time editor sees:
 *
 *   Welcome  →  Tracker  →  Design  →  Publish
 *
 * Each save step goes through the same {@see DraftWorkspaceService}
 * lock + copy-on-write path the standard tabs use; the final publish
 * step calls {@see DraftPublishService::publish()} for an atomic
 * promotion + audit snapshot. Wizard completion is recorded as the
 * internal-state key {@see WizardStateService::KEY_COMPLETED_AT};
 * the banner partial in the other tabs reads that to decide whether
 * to keep nagging.
 */
final class SetupWizardController extends ActionController
{
    private const string SET_IDENTIFIER = 'simplecmp/t3-simplecmp';

    /**
     * Curated theme presets — each preset = a token map applied to
     * the draft theme in step 2. Keep these in lockstep with the
     * STYLE_PRESETS in ThemeDesignerController; this list is a
     * deliberately reduced subset for the wizard (4 cards fit one row).
     *
     * @var array<string, array{label: string, description: string, tokens: array<string, string>}>
     */
    private const array PRESETS = [
        'card-bottom-right' => [
            'label' => 'Karte unten rechts',
            'description' => 'Klassisch — eine Card in der unteren rechten Ecke. Funktioniert für die meisten Sites.',
            'tokens' => ['position' => 'bottom-right'],
        ],
        'bar-bottom' => [
            'label' => 'Leiste unten',
            'description' => 'Vollbreite Banner-Leiste am unteren Viewport-Rand.',
            'tokens' => ['position' => 'bottom-full'],
        ],
        'bar-top' => [
            'label' => 'Leiste oben',
            'description' => 'Vollbreite Banner-Leiste am oberen Viewport-Rand.',
            'tokens' => ['position' => 'top-full'],
        ],
        'modal-center' => [
            'label' => 'Zentriertes Modal',
            'description' => 'Card in der Bildschirmmitte. Höchste Aufmerksamkeit, kann aber als Dark-Pattern wirken.',
            'tokens' => ['position' => 'middle-center'],
        ],
    ];

    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly TrackerRegistry $trackerRegistry,
        private readonly ManagedTrackerRepository $managedTrackerRepository,
        private readonly ThemeRepository $themeRepository,
        private readonly DraftWorkspaceService $draftWorkspace,
        private readonly DraftPublishService $publishService,
        private readonly WizardStateService $wizardState,
        private readonly EffectiveSettingsResolver $effectiveSettings,
    ) {
    }

    public function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setTitle($this->translate('module.wizard.title'));
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    public function welcomeAction(?string $site = null): ResponseInterface
    {
        $sites = $this->collectSites();
        if ($sites === []) {
            $this->moduleTemplate->assign('hasSites', false);
            return $this->moduleTemplate->renderResponse('SetupWizard/Welcome');
        }
        $selected = $this->resolveSelectedSite($site, $sites);
        $this->assignCommon($selected, $sites);
        $this->moduleTemplate->assign('isCompleted', $this->wizardState->isCompleted($selected));
        return $this->moduleTemplate->renderResponse('SetupWizard/Welcome');
    }

    public function skipAction(string $site): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0) {
            $this->addFlashMessage(
                $this->translate('module.wizard.error.notLoggedIn'),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirectToDetections();
        }
        $this->wizardState->markSkipped($site, $beUserId);
        $this->addFlashMessage(
            $this->translate('module.wizard.skipped.message'),
            '',
            ContextualFeedbackSeverity::INFO,
        );
        return $this->redirectToDetections();
    }

    public function reopenAction(string $site): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0) {
            return $this->redirect('welcome', null, null, ['site' => $site]);
        }
        $this->wizardState->reopen($site, $beUserId);
        return $this->redirect('welcome', null, null, ['site' => $site]);
    }

    public function trackerAction(?string $site = null, string $type = ''): ResponseInterface
    {
        $sites = $this->collectSites();
        if ($sites === []) {
            $this->moduleTemplate->assign('hasSites', false);
            return $this->moduleTemplate->renderResponse('SetupWizard/Tracker');
        }
        $selected = $this->resolveSelectedSite($site, $sites);
        $this->assignCommon($selected, $sites);

        $providerOptions = [];
        foreach ($this->trackerRegistry->getKnownTypes() as $trackerType) {
            $providerOptions[$trackerType] = $this->translate('module.trackerSetup.providerLabel.' . $trackerType)
                ?: ucfirst($trackerType);
        }

        $fields = [];
        $values = [];
        if ($type !== '') {
            $provider = $this->trackerRegistry->get($type);
            if ($provider === null) {
                $this->addFlashMessage(
                    sprintf($this->translate('module.trackerSetup.unknownType'), $type),
                    '',
                    ContextualFeedbackSeverity::ERROR,
                );
                return $this->redirect('tracker', null, null, ['site' => $selected]);
            }
            $fields = $this->describeFieldsFor($type);
            $values = ['serviceId' => $provider->getDefaultServiceId()];
        }

        $this->moduleTemplate->assignMultiple([
            'providerOptions' => $providerOptions,
            'selectedType' => $type,
            'fields' => $fields,
            'values' => $values,
            'providerLabel' => $type !== ''
                ? ($this->translate('module.trackerSetup.providerLabel.' . $type) ?: ucfirst($type))
                : '',
        ]);
        return $this->moduleTemplate->renderResponse('SetupWizard/Tracker');
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveTrackerAction(string $site, string $type, array $values = []): ResponseInterface
    {
        $validSites = array_column($this->collectSites(), 'identifier');
        if (!in_array($site, $validSites, true)) {
            $this->addFlashMessage(
                sprintf($this->translate('module.trackerSetup.unknownSite'), $site),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('welcome');
        }

        // Editor chose "no tracker for now" — skip straight to design.
        if ($type === '' || $type === 'none') {
            return $this->redirect('design', null, null, ['site' => $site]);
        }

        $provider = $this->trackerRegistry->get($type);
        if ($provider === null) {
            $this->addFlashMessage(
                sprintf($this->translate('module.trackerSetup.unknownType'), $type),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('tracker', null, null, ['site' => $site]);
        }

        $serviceId = trim((string) ($values['serviceId'] ?? '')) ?: $provider->getDefaultServiceId();
        unset($values['serviceId']);

        try {
            $provider->buildServiceData([...$values, 'type' => $type, 'serviceId' => $serviceId]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlashMessage(
                $e->getMessage(),
                $this->translate('module.trackerSetup.validationFailed'),
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('tracker', null, null, ['site' => $site, 'type' => $type]);
        }

        try {
            $beUserId = $this->ensureSiteDraft($site);
        } catch (\RuntimeException $e) {
            $this->addFlashMessage($e->getMessage(), '', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('tracker', null, null, ['site' => $site, 'type' => $type]);
        }

        $this->managedTrackerRepository->saveDraft(
            $site,
            null,
            $site,
            $type,
            $serviceId,
            $this->normalizeValues($type, $values),
            $beUserId,
        );

        return $this->redirect('design', null, null, ['site' => $site]);
    }

    public function designAction(?string $site = null): ResponseInterface
    {
        $sites = $this->collectSites();
        if ($sites === []) {
            $this->moduleTemplate->assign('hasSites', false);
            return $this->moduleTemplate->renderResponse('SetupWizard/Design');
        }
        $selected = $this->resolveSelectedSite($site, $sites);
        $this->assignCommon($selected, $sites);

        $presets = [];
        foreach (self::PRESETS as $key => $entry) {
            $presets[] = [
                'key' => $key,
                'label' => $entry['label'],
                'description' => $entry['description'],
                'position' => $entry['tokens']['position'] ?? '',
            ];
        }

        $this->moduleTemplate->assign('presets', $presets);
        return $this->moduleTemplate->renderResponse('SetupWizard/Design');
    }

    public function saveDesignAction(string $site, string $preset = ''): ResponseInterface
    {
        $validSites = array_column($this->collectSites(), 'identifier');
        if (!in_array($site, $validSites, true)) {
            $this->addFlashMessage(
                sprintf($this->translate('module.trackerSetup.unknownSite'), $site),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('welcome');
        }

        // Editor chose "keep defaults" — no theme draft persisted.
        if ($preset === '' || $preset === 'default') {
            return $this->redirect('publish', null, null, ['site' => $site]);
        }

        if (!array_key_exists($preset, self::PRESETS)) {
            $this->addFlashMessage(
                $this->translate('module.wizard.design.invalidPreset'),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('design', null, null, ['site' => $site]);
        }

        try {
            $beUserId = $this->ensureSiteDraft($site);
        } catch (\RuntimeException $e) {
            $this->addFlashMessage($e->getMessage(), '', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('design', null, null, ['site' => $site]);
        }

        $this->themeRepository->upsertDraft($site, self::PRESETS[$preset]['tokens'], $beUserId);
        return $this->redirect('publish', null, null, ['site' => $site]);
    }

    public function publishAction(?string $site = null): ResponseInterface
    {
        $sites = $this->collectSites();
        if ($sites === []) {
            $this->moduleTemplate->assign('hasSites', false);
            return $this->moduleTemplate->renderResponse('SetupWizard/Publish');
        }
        $selected = $this->resolveSelectedSite($site, $sites);
        $this->assignCommon($selected, $sites);

        $draftTrackers = method_exists($this->managedTrackerRepository, 'findBySiteDraft')
            ? $this->managedTrackerRepository->findBySiteDraft($selected)
            : [];
        $draftTokens = $this->themeRepository->findBySiteDraft($selected) ?? [];

        $this->moduleTemplate->assignMultiple([
            'draftTrackers' => $this->enrichTrackerRows($draftTrackers),
            'draftHasTheme' => $draftTokens !== [],
            'draftThemePosition' => $draftTokens['position'] ?? null,
            'privacyPolicyUrl' => (string) $this->effectiveSettings->get($selected, 'simplecmp.privacyPolicyUrl', ''),
            'imprintUrl' => (string) $this->effectiveSettings->get($selected, 'simplecmp.imprintUrl', ''),
        ]);

        return $this->moduleTemplate->renderResponse('SetupWizard/Publish');
    }

    public function finishAction(string $site, string $mode = 'publish'): ResponseInterface
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0) {
            $this->addFlashMessage(
                $this->translate('module.wizard.error.notLoggedIn'),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('publish', null, null, ['site' => $site]);
        }

        if ($mode === 'publish') {
            $lock = $this->draftWorkspace->currentLock($site);
            if (!$lock->isUnlocked() && !$lock->isOwnedBy($beUserId)) {
                $this->addFlashMessage(
                    sprintf(
                        $this->translate('module.wizard.publish.lockConflict'),
                        $site,
                        $lock->ownerBeUserId,
                    ),
                    '',
                    ContextualFeedbackSeverity::ERROR,
                );
                return $this->redirect('publish', null, null, ['site' => $site]);
            }
            try {
                $this->publishService->publish($site, $beUserId);
            } catch (\Throwable $e) {
                $this->addFlashMessage(
                    sprintf($this->translate('module.wizard.publish.error'), $e->getMessage()),
                    '',
                    ContextualFeedbackSeverity::ERROR,
                );
                return $this->redirect('publish', null, null, ['site' => $site]);
            }
        }

        $this->wizardState->markCompleted($site, $beUserId);
        $this->addFlashMessage(
            $this->translate(
                $mode === 'publish' ? 'module.wizard.finish.published' : 'module.wizard.finish.draftKept'
            ),
            '',
            ContextualFeedbackSeverity::OK,
        );
        return $this->redirectToDetections();
    }

    // ---- internals ----------------------------------------------------------

    private function ensureSiteDraft(string $site): int
    {
        $beUserId = $this->currentBeUserId();
        if ($beUserId <= 0) {
            throw new \RuntimeException($this->translate('module.wizard.error.notLoggedIn'));
        }
        $lock = $this->draftWorkspace->initializeDraft($site, $beUserId);
        if ($lock->conflict) {
            throw new \RuntimeException(sprintf(
                $this->translate('module.wizard.error.lockConflict'),
                $site,
                $lock->ownerBeUserId,
            ));
        }
        return $beUserId;
    }

    /**
     * @param list<array{identifier: string, label: string}> $sites
     */
    private function assignCommon(string $selected, array $sites): void
    {
        $siteOptions = [];
        foreach ($sites as $entry) {
            $siteOptions[] = [
                'identifier' => $entry['identifier'],
                'label' => $entry['label'],
                'url' => (string) $this->backendUriBuilder->buildUriFromRoute(
                    'simplecmp_detections.SetupWizard_welcome',
                    ['site' => $entry['identifier']],
                ),
            ];
        }

        $this->moduleTemplate->assignMultiple([
            'hasSites' => true,
            'selectedSite' => $selected,
            'siteOptions' => $siteOptions,
            'uri_welcome' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.SetupWizard_welcome',
                ['site' => $selected],
            ),
            'uri_tracker' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.SetupWizard_tracker',
                ['site' => $selected],
            ),
            'uri_design' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.SetupWizard_design',
                ['site' => $selected],
            ),
            'uri_publish' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.SetupWizard_publish',
                ['site' => $selected],
            ),
            'uri_skip' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.SetupWizard_skip',
            ),
            'uri_detectionsTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.DetectionReview_list',
            ),
        ]);
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
     * Mirror of TrackerSetupController::describeFieldsFor() — kept
     * duplicated rather than extracted to a service because it carries
     * no logic, just structure + locallang lookups. If the upstream
     * shape changes both must move in lockstep (provider field shape
     * is the contract).
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
                    $entry['options'][] = [
                        'value' => $value,
                        'label' => $optionLabel !== '' ? $optionLabel : $value,
                        'help' => null,
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
     * @param list<array{uid: int, site: string, tracker_type: string, service_id: string, config: array<string, mixed>}> $rows
     * @return list<array{uid: int, site: string, tracker_type: string, service_id: string, config: array<string, mixed>, providerLabel: string}>
     */
    private function enrichTrackerRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $type = $row['tracker_type'];
            $providerLabel = $this->translate('module.trackerSetup.providerLabel.' . $type) ?: ucfirst($type);
            $out[] = $row + ['providerLabel' => $providerLabel];
        }
        return $out;
    }

    private function currentBeUserId(): int
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUser === null || !isset($beUser->user['uid'])) {
            return 0;
        }
        return (int) $beUser->user['uid'];
    }

    private function redirectToDetections(): ResponseInterface
    {
        $fallback = (string) $this->backendUriBuilder->buildUriFromRoute(
            'simplecmp_detections.DetectionReview_list',
        );
        return $this->responseFactory->createResponse(303)
            ->withHeader('Location', $fallback);
    }

    private function translate(string $key): string
    {
        if (str_starts_with($key, 'LLL:')) {
            return $this->getLanguageService()->sL($key);
        }
        return $this->getLanguageService()->sL('LLL:EXT:simplecmp/Resources/Private/Language/locallang_mod.xlf:' . $key);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
