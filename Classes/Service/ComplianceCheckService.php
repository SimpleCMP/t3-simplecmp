<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Core\Site\Entity\Site;
use SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use SimpleCMP\T3SimpleCmp\Service\LockState;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;

/**
 * PHP mirror of the upstream `simplecmp` bundle's
 * `src/audit/index.ts` compliance checks. Same `id` per check,
 * same `section` references (pointing into the bundle's
 * `docs/legal-compliance.md`), same severity assignments — but
 * evaluated against the TYPO3-side configuration (site settings +
 * service registry) instead of a `SimpleCMPConfig` literal.
 *
 * Why a mirror instead of consuming the JS surface: the BE module is
 * PHP, so running the bundle's `simplecmp.audit(config)` would
 * require a Node bridge. The check list is small (~7 entries) and
 * predicates are trivial; mirroring is the cheaper-and-shorter path.
 *
 * **Keep in lockstep with upstream.** When a check is added,
 * removed, or its severity changes upstream, mirror the change here
 * by `id`. Drift is a real risk — the bundle's CHANGELOG callouts
 * "feat(audit)" are the trigger to re-sync.
 *
 * @phpstan-type Result array{
 *     id: string,
 *     section: string,
 *     severity: 'critical' | 'warning' | 'info',
 *     titleKey: string,
 *     detailKey: string,
 *     context: array<string, scalar>,
 *     passed: bool,
 * }
 */
final readonly class ComplianceCheckService
{
    public function __construct(
        private ServiceRepository $serviceRepository,
        private TranslationOverrideRepository $overrideRepository,
        private ThemeRepository $themeRepository,
        private EffectiveSettingsResolver $effectiveSettings,
        private DraftWorkspaceService $draftWorkspace,
    ) {
    }

    /**
     * Theme-token pairs that visually relate to each other. When the
     * editor overrides one but leaves the other at the bundle's
     * default, the rendered banner gets a visual mismatch — e.g.
     * primary stays brand-red but the hover state reverts to the
     * default's faded green. The heuristic flags these imbalances
     * so the editor confirms the choice (intentional) or completes
     * the pair (forgot).
     *
     * Why each pair matters:
     *   - color-primary / color-primary-hover: focus-outline + link
     *     colors carry the primary; hover should track it so the
     *     accent feels coherent on `:hover` / `:focus-visible`.
     *   - color-bg / color-bg-alt: bg is the banner card surface,
     *     bg-alt is the button surface. Their relationship defines
     *     the button-stands-out-from-card affordance; tuning one
     *     without the other tends to either erase the button shape
     *     or make it scream.
     *   - color-text / color-text-muted: body text and the muted
     *     policy-link text — when one is overridden the muted
     *     value should usually move with it (lighter shade of the
     *     new body color, not the default's grey-on-new-body).
     *
     * @var list<array{tokens: array{0: string, 1: string}, label: string}>
     */
    /**
     * Paired theme tokens — the heuristic flags a site where exactly
     * one of the pair was overridden (the other defaults). `labelKey`
     * resolves at audit time against locallang so the editor sees the
     * label in their BE language.
     */
    private const array PAIRED_TOKENS = [
        ['tokens' => ['color-primary', 'color-primary-hover'], 'labelKey' => 'audit.pairedToken.primary'],
        ['tokens' => ['color-bg', 'color-bg-alt'], 'labelKey' => 'audit.pairedToken.background'],
        ['tokens' => ['color-text', 'color-text-muted'], 'labelKey' => 'audit.pairedToken.text'],
    ];

    /**
     * Decline-label phrases that read as deferral rather than refusal.
     * Mirrors upstream `src/audit/heuristics.ts` WEAK_DECLINE_PATTERNS.
     *
     * @var array<string, list<array{phrase: string, reason: string}>>
     */
    private const array WEAK_DECLINE_PATTERNS = [
        'de' => [
            ['phrase' => 'vielleicht später', 'reason' => 'verschiebt die Entscheidung statt sie zu treffen'],
            ['phrase' => 'nicht jetzt', 'reason' => 'klingt aufschiebend, nicht ablehnend'],
            ['phrase' => 'überspringen', 'reason' => 'klingt nach „später", nicht „nein"'],
            ['phrase' => 'schließen', 'reason' => 'beschreibt eine UI-Aktion, nicht eine Ablehnung'],
            ['phrase' => 'weiter ohne', 'reason' => 'unklar — was genau wird abgelehnt?'],
        ],
        'en' => [
            ['phrase' => 'maybe later', 'reason' => 'defers instead of refuses'],
            ['phrase' => 'not now', 'reason' => 'sounds like postponement, not refusal'],
            ['phrase' => 'skip', 'reason' => 'reads as "later", not "no"'],
            ['phrase' => 'close', 'reason' => 'describes a UI action, not a rejection'],
            ['phrase' => 'continue without', 'reason' => 'ambiguous — what is actually being rejected?'],
            ['phrase' => 'remind me later', 'reason' => 'defers instead of refuses'],
        ],
        'fr' => [
            ['phrase' => 'plus tard', 'reason' => 'reporte au lieu de refuser'],
            ['phrase' => 'pas maintenant', 'reason' => 'sonne comme un report, pas un refus'],
            ['phrase' => 'fermer', 'reason' => 'décrit une action UI, pas un refus'],
        ],
        'it' => [
            ['phrase' => 'più tardi', 'reason' => 'rimanda invece di rifiutare'],
            ['phrase' => 'non ora', 'reason' => 'suona come un rinvio, non un rifiuto'],
            ['phrase' => 'chiudi', 'reason' => "descrive un'azione UI, non un rifiuto"],
        ],
        'es' => [
            ['phrase' => 'más tarde', 'reason' => 'aplaza en lugar de rechazar'],
            ['phrase' => 'ahora no', 'reason' => 'suena como aplazamiento, no rechazo'],
            ['phrase' => 'cerrar', 'reason' => 'describe una acción UI, no un rechazo'],
        ],
        'nl' => [
            ['phrase' => 'later', 'reason' => 'verschuift de keuze in plaats van te weigeren'],
            ['phrase' => 'niet nu', 'reason' => 'klinkt als uitstel, geen weigering'],
            ['phrase' => 'sluiten', 'reason' => 'beschrijft een UI-actie, geen weigering'],
            ['phrase' => 'overslaan', 'reason' => 'klinkt als "later", niet "nee"'],
        ],
    ];

    /**
     * Marketing-nudge phrases in banner descriptions.
     * Mirrors upstream `src/audit/heuristics.ts` MARKETING_NUDGE_PATTERNS.
     *
     * @var array<string, list<array{phrase: string, reason: string}>>
     */
    private const array MARKETING_NUDGE_PATTERNS = [
        'de' => [
            ['phrase' => 'erlebnis verbessern', 'reason' => '„Erlebnis" ist Marketing-Sprache'],
            ['phrase' => 'verbessere dein erlebnis', 'reason' => 'manipulativer Nudge zur Zustimmung'],
            ['phrase' => 'verbessern sie ihr erlebnis', 'reason' => 'manipulativer Nudge zur Zustimmung'],
            ['phrase' => 'volle funktionalität', 'reason' => 'suggeriert eingeschränkten Service bei Ablehnung'],
            ['phrase' => 'volles erlebnis', 'reason' => 'suggeriert eingeschränkten Service bei Ablehnung'],
            ['phrase' => 'vertrauensvolle partner', 'reason' => 'vage — benenne die Verantwortlichen'],
            ['phrase' => 'optimal erleben', 'reason' => '„optimal" ist subjektiv und manipulativ'],
            ['phrase' => 'personalisieren sie ihren besuch', 'reason' => 'Marketing-Nudge zur Zustimmung'],
            ['phrase' => 'personalisiere deinen besuch', 'reason' => 'Marketing-Nudge zur Zustimmung'],
        ],
        'en' => [
            ['phrase' => 'improve your experience', 'reason' => 'marketing nudge toward acceptance'],
            ['phrase' => 'enhance your experience', 'reason' => 'marketing nudge toward acceptance'],
            ['phrase' => 'get the full experience', 'reason' => 'suggests degraded service on refusal'],
            ['phrase' => 'full functionality', 'reason' => 'suggests degraded service on refusal'],
            ['phrase' => 'trusted partners', 'reason' => 'vague — name the controllers'],
            ['phrase' => 'personalize your visit', 'reason' => 'marketing nudge toward acceptance'],
            ['phrase' => 'continue to enjoy', 'reason' => 'marketing nudge'],
            ['phrase' => 'tailored experience', 'reason' => 'marketing nudge toward acceptance'],
        ],
        'fr' => [
            ['phrase' => 'meilleure expérience', 'reason' => 'langage marketing, pousse vers l’acceptation'],
            ['phrase' => 'expérience optimale', 'reason' => '« optimal » est subjectif et manipulateur'],
            ['phrase' => 'partenaires de confiance', 'reason' => 'vague — nommer les responsables'],
            ['phrase' => 'personnaliser votre visite', 'reason' => 'nudge marketing vers l’acceptation'],
        ],
        'it' => [
            ['phrase' => 'migliore esperienza', 'reason' => 'linguaggio marketing, spinge verso l’accettazione'],
            ['phrase' => 'esperienza ottimale', 'reason' => '«ottimale» è soggettivo e manipolativo'],
            ['phrase' => 'partner di fiducia', 'reason' => 'vago — nominare i titolari'],
            ['phrase' => 'personalizza la tua visita', 'reason' => 'nudge marketing verso l’accettazione'],
        ],
        'es' => [
            ['phrase' => 'mejor experiencia', 'reason' => 'lenguaje marketing, empuja hacia la aceptación'],
            ['phrase' => 'experiencia óptima', 'reason' => '«óptima» es subjetiva y manipuladora'],
            ['phrase' => 'socios de confianza', 'reason' => 'vago — nombrar los responsables'],
            ['phrase' => 'personalizar tu visita', 'reason' => 'nudge marketing hacia la aceptación'],
        ],
        'nl' => [
            ['phrase' => 'betere ervaring', 'reason' => 'marketingtaal, duwt richting acceptatie'],
            ['phrase' => 'optimale ervaring', 'reason' => '"optimaal" is subjectief en manipulatief'],
            ['phrase' => 'vertrouwde partners', 'reason' => 'vaag — noem de verwerkingsverantwoordelijken'],
            ['phrase' => 'personaliseer je bezoek', 'reason' => 'marketingnudge richting acceptatie'],
        ],
    ];

    /**
     * Run all checks against the site and return per-check findings.
     * Order matches upstream `src/audit/index.ts` `CHECKS` array so
     * downstream code can match by index when needed.
     *
     * `$preferDraft` makes the audit evaluate the editor's pending
     * draft instead of the published ("freigegebene") banner config —
     * the BE designer passes `true` so the findings shown next to the
     * draft form actually describe what the editor is about to publish,
     * not the older live state. Resolution is per draft scope with a
     * live fallback: the global service registry switches to its draft
     * only when a global draft exists, the per-site theme / translation
     * overrides switch only when a site-scoped draft exists. Site
     * Settings are NOT part of the draft workspace (theme / overrides /
     * services are), so the settings-based checks always read the active
     * configuration regardless of `$preferDraft`.
     *
     * @return list<Result>
     */
    public function audit(Site $site, bool $preferDraft = false): array
    {
        $siteId = $site->getIdentifier();
        $serviceDraft = $preferDraft && $this->draftWorkspace->hasDraft(LockState::SCOPE_GLOBAL);
        $siteDraft = $preferDraft && $this->draftWorkspace->hasDraft($siteId);

        $services = $serviceDraft
            ? $this->serviceRepository->findAllDraft(LockState::SCOPE_GLOBAL)
            : $this->serviceRepository->findAll();
        $themeTokens = ($siteDraft
            ? $this->themeRepository->findBySiteDraft($siteId)
            : $this->themeRepository->findBySite($siteId)) ?? [];
        $overrides = $siteDraft
            ? $this->overrideRepository->findBySiteDraft($siteId)
            : $this->overrideRepository->findBySite($siteId);

        $results = [];

        $results[] = $this->checkPrivacyPolicyUrl($site);
        $results[] = $this->checkFirstLayerReject($site);
        $results[] = $this->checkOptInDefaults($services);
        $results[] = $this->checkPreConsentBlocking($site);
        $results[] = $this->checkPersistentRevocationTrigger($site);
        $results[] = $this->checkImprintUrlDach($site);
        $results[] = $this->checkServicesHavePurposes($services);
        $results[] = $this->checkDeclineLabelClarity($overrides);
        $results[] = $this->checkNoMarketingNudgeInDescription($overrides);
        $results[] = $this->checkDescriptionLength($overrides);
        $results[] = $this->checkPairedTokenOverrides($themeTokens);
        $results[] = $this->checkBannerContrast($themeTokens);
        $results[] = $this->checkButtonEqualProminence($themeTokens);
        $results[] = $this->checkAccessibleNameOverrides($overrides);

        return $results;
    }

    /**
     * Length thresholds for the banner-description heuristic.
     * Mirrors upstream `src/audit/heuristics.ts` constants. Keep
     * in lockstep — a value change upstream is a re-sync trigger
     * here.
     */
    private const int DESCRIPTION_MIN_CHARS = 80;
    private const int DESCRIPTION_MAX_CHARS = 600;

    /**
     * Worst severity across a result set. Mirrors upstream
     * `auditWorstSeverity()`. Used to drive a top-level status
     * badge in the BE module.
     *
     * @param list<Result> $results
     * @return 'critical' | 'warning' | 'info'
     */
    public function worstSeverity(array $results): string
    {
        $worst = 'info';
        foreach ($results as $result) {
            if ($result['severity'] === 'critical') {
                return 'critical';
            }
            if ($result['severity'] === 'warning') {
                $worst = 'warning';
            }
        }
        return $worst;
    }

    /**
     * @return Result
     */
    private function checkPrivacyPolicyUrl(Site $site): array
    {
        $url = (string) $this->effectiveSettings->get($site->getIdentifier(), 'simplecmp.privacyPolicyUrl', '');
        if ($url !== '' && $url !== '#') {
            return $this->pass('privacy-policy-url', '1.5');
        }
        return $this->fail('privacy-policy-url', '1.5', 'critical');
    }

    /**
     * @return Result
     */
    private function checkFirstLayerReject(Site $site): array
    {
        // TYPO3 currently doesn't expose `hideDeclineAll` through any
        // site setting — the FE asset listener always sends the
        // bundle's safe default (`hideDeclineAll: false` implicit).
        // Surface the check anyway so the BE makes it visible that
        // the property is being honoured.
        if ((bool) $this->effectiveSettings->get($site->getIdentifier(), 'simplecmp.hideDeclineAll', false) === true) {
            return $this->fail('first-layer-reject', '1.3', 'critical');
        }
        return $this->pass('first-layer-reject', '1.3');
    }

    /**
     * @param list<array<string, mixed>> $services
     * @return Result
     */
    private function checkOptInDefaults(array $services): array
    {
        $offenders = [];
        foreach ($services as $service) {
            // The TYPO3 service-registry shape carries `required` and
            // `default` flags directly on each row. `required: true`
            // means the service is essential — opt-in is not legally
            // required. Skip those.
            if (($service['required'] ?? false) === true) {
                continue;
            }
            if (($service['default'] ?? false) === true) {
                $offenders[] = (string) ($service['id'] ?? $service['name'] ?? '?');
            }
        }
        if ($offenders === []) {
            return $this->pass('opt-in-defaults', '1.1');
        }
        return $this->fail('opt-in-defaults', '1.1', 'critical', [
            'count' => count($offenders),
            'sample' => implode(', ', array_slice($offenders, 0, 5)),
            'more' => count($offenders) > 5 ? count($offenders) - 5 : 0,
        ]);
    }

    /**
     * @return Result
     */
    private function checkPreConsentBlocking(Site $site): array
    {
        if ((bool) $this->effectiveSettings->get($site->getIdentifier(), 'simplecmp.universalBlocking.enabled', true) === true) {
            return $this->pass('pre-consent-blocking', '1.7');
        }
        return $this->fail('pre-consent-blocking', '1.7', 'critical');
    }

    /**
     * @return Result
     */
    private function checkPersistentRevocationTrigger(Site $site): array
    {
        // The FE asset listener always passes a `floatingTrigger` to
        // `cmp.init()`, using `simplecmp.floatingTriggerLabel` as the
        // visible text. If the admin has cleared the label, we treat
        // the trigger as effectively disabled — there's no visible
        // affordance to reopen consent.
        $label = (string) $this->effectiveSettings->get($site->getIdentifier(), 'simplecmp.floatingTriggerLabel', '');
        if (trim($label) !== '') {
            return $this->pass('persistent-revocation-trigger', '1.6');
        }
        return $this->fail('persistent-revocation-trigger', '1.6', 'warning');
    }

    /**
     * @return Result
     */
    private function checkImprintUrlDach(Site $site): array
    {
        $url = (string) $this->effectiveSettings->get($site->getIdentifier(), 'simplecmp.imprintUrl', '');
        if ($url !== '' && $url !== '#') {
            return $this->pass('imprint-url-dach', '1.5');
        }
        return $this->fail('imprint-url-dach', '1.5', 'warning');
    }

    /**
     * @param list<array<string, mixed>> $services
     * @return Result
     */
    private function checkServicesHavePurposes(array $services): array
    {
        $offenders = [];
        foreach ($services as $service) {
            $purposes = $service['purposes'] ?? [];
            if (!is_array($purposes) || $purposes === []) {
                $offenders[] = (string) ($service['id'] ?? $service['name'] ?? '?');
            }
        }
        if ($offenders === []) {
            return $this->pass('services-have-purposes', '1.4');
        }
        return $this->fail('services-have-purposes', '1.4', 'warning', [
            'count' => count($offenders),
            'sample' => implode(', ', array_slice($offenders, 0, 5)),
            'more' => count($offenders) > 5 ? count($offenders) - 5 : 0,
        ]);
    }

    /**
     * Heuristic — flags weak / deferring decline labels in editor
     * overrides. Mirrors upstream
     * `heuristics.checkDeclineLabelClarity` predicate by language.
     *
     * @param array<string, array{tone: ?string, overrides: array<string, string>}>|null $overrides
     * @return Result
     */
    private function checkDeclineLabelClarity(?array $overrides): array
    {
        $hits = $this->scanOverrideKey(
            $overrides,
            'decline',
            self::WEAK_DECLINE_PATTERNS,
            includeMatchedPhrase: false,
        );
        if ($hits === []) {
            return $this->pass('heuristic-decline-label-clarity', '2.2');
        }
        return $this->fail(
            'heuristic-decline-label-clarity',
            '2.2',
            'warning',
            [
                'count' => count($hits),
                'sample' => "\n  - " . implode("\n  - ", $hits),
            ],
        );
    }

    /**
     * Heuristic — flags marketing-nudge phrases in description
     * overrides. Mirrors upstream
     * `heuristics.checkNoMarketingNudgeInDescription`.
     *
     * @param array<string, array{tone: ?string, overrides: array<string, string>}>|null $overrides
     * @return Result
     */
    private function checkNoMarketingNudgeInDescription(?array $overrides): array
    {
        $hits = $this->scanOverrideKey(
            $overrides,
            'consentNotice.description',
            self::MARKETING_NUDGE_PATTERNS,
            includeMatchedPhrase: true,
        );
        if ($hits === []) {
            return $this->pass('heuristic-no-marketing-nudge-in-description', '2.3');
        }
        return $this->fail(
            'heuristic-no-marketing-nudge-in-description',
            '2.3',
            'warning',
            [
                'count' => count($hits),
                'sample' => "\n  - " . implode("\n  - ", $hits),
            ],
        );
    }

    /**
     * Heuristic — flags banner-description overrides that fall
     * outside the readable length band. Mirrors upstream
     * `heuristics.checkDescriptionLength` predicate.
     *
     * @param array<string, array{tone: ?string, overrides: array<string, string>}>|null $rows
     * @return Result
     */
    private function checkDescriptionLength(?array $rows): array
    {
        if ($rows === null || $rows === []) {
            return $this->pass('heuristic-description-length', '2.1');
        }
        $issues = [];
        foreach ($rows as $lang => $entry) {
            $text = $entry['overrides']['consentNotice.description'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            $len = mb_strlen($text);
            if ($len < self::DESCRIPTION_MIN_CHARS) {
                $issues[] = sprintf(
                    $this->translate('audit.descriptionLength.tooShort'),
                    $lang,
                    $len,
                    self::DESCRIPTION_MIN_CHARS,
                );
            } elseif ($len > self::DESCRIPTION_MAX_CHARS) {
                $issues[] = sprintf(
                    $this->translate('audit.descriptionLength.tooLong'),
                    $lang,
                    $len,
                    self::DESCRIPTION_MAX_CHARS,
                );
            }
        }
        if ($issues === []) {
            return $this->pass('heuristic-description-length', '2.1');
        }
        return $this->fail('heuristic-description-length', '2.1', 'warning', [
            'count' => count($issues),
            'sample' => "\n  - " . implode("\n  - ", $issues),
        ]);
    }

    /**
     * Heuristic — flags theme-token pairs where the editor overrode
     * one half but left the other at the bundle's default. The
     * resulting banner gets a visual mismatch (hover state stops
     * tracking primary, button background no longer relates to the
     * card surface, etc.). Warning severity — the editor may have
     * intentionally left one at the default and just wants the
     * confirmation hint; the audit names the pair so they can.
     *
     * TYPO3-only check: the BE designer's theme-override surface is
     * the only place a token "override set" exists. Upstream
     * `simplecmp` consumes overrides via raw CSS variables at FE
     * mount, with no per-token override-vs-default distinction.
     *
     * @param array<string, scalar> $tokens
     * @return Result
     */
    private function checkPairedTokenOverrides(array $tokens): array
    {
        // Drop non-color tokens — `position`, `theme`, `layout` aren't
        // pairs and would otherwise dilute the check's scope.
        $colorOverrides = [];
        foreach ($tokens as $key => $_) {
            if (is_string($key) && str_starts_with($key, 'color-')) {
                $colorOverrides[$key] = true;
            }
        }
        $imbalances = [];
        foreach (self::PAIRED_TOKENS as $pair) {
            [$a, $b] = $pair['tokens'];
            $aSet = isset($colorOverrides[$a]);
            $bSet = isset($colorOverrides[$b]);
            if ($aSet xor $bSet) {
                $imbalances[] = sprintf(
                    $this->translate('audit.pairedToken.imbalance'),
                    $this->translate($pair['labelKey']),
                    $aSet ? $a : $b,
                    $aSet ? $b : $a,
                );
            }
        }
        if ($imbalances === []) {
            return $this->pass('heuristic-paired-token-overrides', '1.2');
        }
        return $this->fail(
            'heuristic-paired-token-overrides',
            '1.2',
            'warning',
            [
                'count' => count($imbalances),
                'sample' => "\n  - " . implode("\n  - ", $imbalances),
            ],
        );
    }

    /**
     * Banner contrast — verifies that the colour pairs the visitor
     * actually reads against meet WCAG 2.1 AA contrast (4.5:1 for
     * body text).
     *
     * Three pairs are evaluated against the live token state:
     *   1. body text on banner background (color-text vs color-bg)
     *   2. muted text on banner background (text-muted vs color-bg)
     *   3. primary-button text on primary (white text on
     *      color-primary — the bundle hardcodes white text on the
     *      modal Save/Accept buttons)
     *
     * When `colorPaletteLocked` is `1`, the FE renders SAFE_PALETTE
     * regardless of stored overrides — the check skips entirely
     * (the palette is audited offline at design time, no per-render
     * verification needed).
     *
     * Returns a `warning` rather than `critical` finding: the
     * surrounding `Eigene Farben aktiv` alert already signals risk,
     * and listing concrete failing pairs is a follow-up nudge.
     *
     * @param array<string, scalar> $stored
     * @return array<string, mixed>
     */
    private function checkBannerContrast(array $stored): array
    {
        // Color-lock active → SAFE_PALETTE wins on the live site, no
        // contrast risk from this site's tokens.
        if (($stored['colorPaletteLocked'] ?? '1') === '1') {
            return $this->pass('heuristic-banner-contrast', '2.1');
        }

        // Merge stored values on top of DEFAULT_TOKENS so missing keys
        // resolve to their defaults (matching the FE render path).
        $tokens = array_merge(
            \SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController::DEFAULT_TOKENS,
            array_filter($stored, static fn($v) => is_string($v) && $v !== ''),
        );

        $checks = [
            ['nameKey' => 'audit.contrast.bodyOnBanner', 'fg' => $tokens['color-text'] ?? null, 'bg' => $tokens['color-bg'] ?? null],
            ['nameKey' => 'audit.contrast.mutedOnBanner', 'fg' => $tokens['color-text-muted'] ?? null, 'bg' => $tokens['color-bg'] ?? null],
            // The bundle's modal Save/Accept buttons render with
            // `color: white` against `background: var(--simplecmp-color-primary)`.
            ['nameKey' => 'audit.contrast.acceptButton', 'fg' => '#ffffff', 'bg' => $tokens['color-primary'] ?? null],
        ];

        $failures = [];
        foreach ($checks as $c) {
            if (!is_string($c['fg']) || !is_string($c['bg'])) {
                continue;
            }
            $ratio = $this->contrastRatio($c['fg'], $c['bg']);
            if ($ratio === null || $ratio >= 4.5) {
                continue;
            }
            $failures[] = sprintf(
                $this->translate('audit.contrast.failure'),
                $this->translate($c['nameKey']),
                $c['fg'],
                $c['bg'],
                number_format($ratio, 1, ',', ''),
            );
        }

        if ($failures === []) {
            return $this->pass('heuristic-banner-contrast', '2.1');
        }
        return $this->fail(
            'heuristic-banner-contrast',
            '2.1',
            'warning',
            [
                'count' => count($failures),
                'sample' => "\n  - " . implode("\n  - ", $failures),
            ],
        );
    }

    /**
     * Banner-button equal-prominence — BGH "Cookie II" (1 BvR 2783/19,
     * 2020) requires that Accept and Decline (and Configure) on the
     * first banner layer not differ in visual weight. Out of the box
     * the bundle gives all three buttons the same neutral background
     * (`--simplecmp-color-bg-alt`). Editors can break that baseline
     * by setting `color-accept-bg`, `color-decline-bg`, or
     * `color-configure-bg` independently — when they do, surface a
     * warning so the compliance trade-off is visible.
     *
     * @param array<string, scalar> $stored
     * @return Result
     */
    private function checkButtonEqualProminence(array $stored): array
    {
        // Color-lock active → SAFE_PALETTE wins on the live site and
        // any per-button overrides are inert. No equal-prominence risk.
        if (($stored['colorPaletteLocked'] ?? '1') === '1') {
            return $this->pass('heuristic-button-equal-prominence', '2.1');
        }
        $overrides = [];
        $labels = [
            'color-accept-bg' => 'audit.button.accept',
            'color-decline-bg' => 'audit.button.decline',
            'color-configure-bg' => 'audit.button.configure',
        ];
        foreach ($labels as $key => $labelKey) {
            $value = $stored[$key] ?? '';
            if (is_string($value) && $value !== '') {
                $overrides[] = $this->translate($labelKey) . ': ' . $value;
            }
        }
        if ($overrides === []) {
            return $this->pass('heuristic-button-equal-prominence', '2.1');
        }
        return $this->fail(
            'heuristic-button-equal-prominence',
            '2.1',
            'warning',
            [
                'count' => count($overrides),
                'sample' => "\n  - " . implode("\n  - ", $overrides),
            ],
        );
    }

    /**
     * WCAG 2.1 contrast ratio between two `#rrggbb` colour values,
     * computed via the relative-luminance formula. Returns null if
     * either input isn't a valid 6-digit hex.
     */
    private function contrastRatio(string $hexA, string $hexB): ?float
    {
        $a = $this->relativeLuminance($hexA);
        $b = $this->relativeLuminance($hexB);
        if ($a === null || $b === null) {
            return null;
        }
        $lighter = max($a, $b);
        $darker = min($a, $b);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Relative luminance per WCAG 2.1 §1.4.3. Accepts `#rgb` or
     * `#rrggbb` hex notation; returns null otherwise.
     */
    private function relativeLuminance(string $hex): ?float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }
        $rgb = [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
        $linear = array_map(
            static fn(float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $rgb,
        );
        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * Walk every language's override at `$dottedKey`, run each text
     * against the per-language pattern list, return one summary line
     * per hit. `includeMatchedPhrase` controls whether the matched
     * phrase is quoted in the summary (useful for marketing nudges
     * where the phrase IS the point) vs. quoting the full text
     * (useful for short labels like decline where the full label is
     * the point).
     *
     * @param array<string, list<array{phrase: string, reason: string}>> $patterns
     * @return list<string>
     */
    /**
     * Translation override keys that drive a visible accessible-name on
     * the rendered banner. Empty values for these turn a button or the
     * banner region into something that screen readers can't identify
     * — WCAG 4.1.2 / 2.4.6.
     *
     * Mirrors the surface checked by upstream `src/audit/dom.ts`
     * `checkAccessibleNames` (id `dom-accessible-names`, REQ-N11), with
     * one constraint: the DOM check runs against the rendered shadow
     * DOM and can see EVERY action element. The PHP mirror runs at
     * BE-design time without a rendered banner, so it can only verify
     * the editor-curated override layer — if an editor blanked a label
     * here, the runtime banner WILL render unnamed. The bundle's
     * defaults still provide names when no override is set; that path
     * passes here trivially.
     *
     * @var list<string>
     */
    private const array ACCESSIBLE_NAME_OVERRIDE_KEYS = [
        // Main banner action buttons — their text is the accessible name.
        'acceptAll',
        'decline',
        'acceptSelected',
        // Region label on the banner card itself.
        'consentNotice.title',
        // Modal footer + close action.
        'ok',
        'save',
        'close',
        // Provider-info modal trigger ("Weitere Informationen ›").
        'contextualConsent.learnMore',
    ];

    /**
     * REQ-N11 mirror — `dom-accessible-names`.
     *
     * Flags blank override values for any banner-action label or the
     * banner-region title. Editors who explicitly clear a label produce
     * a button or region with no accessible name once the banner renders
     * — assistive tech can't identify or operate it, so consent that
     * can't be perceived isn't valid consent.
     *
     * The static / PHP side only catches the editor-blanked-the-override
     * case. The DOM-level companion runs live via `?simplecmp_audit=1`
     * and additionally catches missing aria-label / aria-labelledby
     * attributes the templates would have rendered — that side cannot
     * be mirrored statically (no rendered shadow DOM at BE time). Same
     * `id`, same severity, so a BE-passing config plus a passing live
     * audit together give the full REQ-N11 coverage.
     *
     * @param array<string, array{tone: ?string, overrides: array<string, string>}>|null $rows
     * @return Result
     */
    private function checkAccessibleNameOverrides(?array $rows): array
    {
        if ($rows === null || $rows === []) {
            return $this->pass('dom-accessible-names', '2.2');
        }

        $blanks = [];
        foreach ($rows as $lang => $entry) {
            $overrides = $entry['overrides'] ?? [];
            if (!is_array($overrides)) {
                continue;
            }
            foreach (self::ACCESSIBLE_NAME_OVERRIDE_KEYS as $key) {
                if (!array_key_exists($key, $overrides)) {
                    // No override → bundle default applies → non-empty name.
                    continue;
                }
                $value = $overrides[$key];
                if (is_string($value) && trim($value) !== '') {
                    continue;
                }
                $blanks[] = sprintf('[%s] %s', (string) $lang, $key);
            }
        }

        if ($blanks === []) {
            return $this->pass('dom-accessible-names', '2.2');
        }

        return $this->fail(
            'dom-accessible-names',
            '2.2',
            'critical',
            [
                'count' => count($blanks),
                'sample' => "\n  - " . implode("\n  - ", $blanks),
            ],
        );
    }

    /**
     * @param array<string, array{tone: ?string, overrides: array<string, string>}>|null $rows
     * @param array<string, list<array{phrase: string, reason: string}>> $patterns
     * @return list<string>
     */
    private function scanOverrideKey(
        ?array $rows,
        string $dottedKey,
        array $patterns,
        bool $includeMatchedPhrase,
    ): array {
        if ($rows === null || $rows === []) {
            return [];
        }
        $hits = [];
        foreach ($rows as $lang => $entry) {
            $text = $entry['overrides'][$dottedKey] ?? null;
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            $hit = $this->findPatternHit($patterns, (string) $lang, $text);
            if ($hit === null) {
                continue;
            }
            $hits[] = $includeMatchedPhrase
                ? sprintf('[%s] "%s" — %s', $lang, $hit['phrase'], $hit['reason'])
                : sprintf('[%s] "%s" — %s', $lang, $text, $hit['reason']);
        }
        return $hits;
    }

    /**
     * Case-insensitive substring match — first hit wins. Returns
     * `null` when the language has no pattern list or no phrase
     * matches.
     *
     * @param array<string, list<array{phrase: string, reason: string}>> $patterns
     * @return array{phrase: string, reason: string}|null
     */
    private function findPatternHit(array $patterns, string $lang, string $text): ?array
    {
        $list = $patterns[strtolower($lang)] ?? null;
        if ($list === null) {
            return null;
        }
        $haystack = mb_strtolower($text);
        foreach ($list as $pattern) {
            if (str_contains($haystack, mb_strtolower($pattern['phrase']))) {
                return $pattern;
            }
        }
        return null;
    }

    /**
     * Build a passing result. Title + detail are deferred to the
     * template via translation keys (`designer.audit.title.<id>` and
     * `designer.audit.pass.<id>`), so the messages render in the
     * editor's BE language without the service needing to know about
     * i18n.
     *
     * @return Result
     */
    private function pass(string $id, string $section): array
    {
        return [
            'id' => $id,
            'section' => $section,
            'severity' => 'info',
            'titleKey' => 'designer.audit.title.' . $id,
            'detailKey' => 'designer.audit.pass.' . $id,
            'context' => [],
            'passed' => true,
        ];
    }

    /**
     * Build a failing result. The detail key is
     * `designer.audit.fail.<id>` and may consume `context` arguments
     * (count / sample / more) for `sprintf`-style placeholders so the
     * BE shows e.g. "3 Dienste sind betroffen: foo, bar, baz" in
     * German without the service having to assemble the sentence.
     *
     * @param array<string, scalar> $context
     * @return Result
     */
    private function fail(
        string $id,
        string $section,
        string $severity,
        array $context = [],
    ): array {
        return [
            'id' => $id,
            'section' => $section,
            'severity' => $severity,
            'titleKey' => 'designer.audit.title.' . $id,
            'detailKey' => 'designer.audit.fail.' . $id,
            'context' => $context,
            'passed' => false,
        ];
    }

    /**
     * Resolve a locallang key from `locallang_design.xlf`. Falls back
     * to the key itself when the language service is unavailable
     * (defensive for non-BE invocation paths) — the fallback string
     * is loud enough to surface the missing-translation case in the
     * BE audit panel without crashing the request.
     */
    private function translate(string $key): string
    {
        $service = $GLOBALS['LANG'] ?? null;
        if (!$service instanceof \TYPO3\CMS\Core\Localization\LanguageService) {
            return $key;
        }
        $translated = $service->sL(
            'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_design.xlf:' . $key,
        );
        return $translated !== '' ? $translated : $key;
    }
}
