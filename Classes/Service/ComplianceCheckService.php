<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Core\Site\Entity\Site;
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
    private const array PAIRED_TOKENS = [
        ['tokens' => ['color-primary', 'color-primary-hover'], 'label' => 'Primärfarbe + Hover'],
        ['tokens' => ['color-bg', 'color-bg-alt'], 'label' => 'Karten- + Button-Hintergrund'],
        ['tokens' => ['color-text', 'color-text-muted'], 'label' => 'Text + dezenter Text'],
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
     * @return list<Result>
     */
    public function audit(Site $site): array
    {
        $settings = $site->getSettings();
        $results = [];

        $results[] = $this->checkPrivacyPolicyUrl($settings);
        $results[] = $this->checkFirstLayerReject($settings);
        $results[] = $this->checkOptInDefaults();
        $results[] = $this->checkPreConsentBlocking($settings);
        $results[] = $this->checkPersistentRevocationTrigger($settings);
        $results[] = $this->checkImprintUrlDach($settings);
        $results[] = $this->checkServicesHavePurposes();
        $results[] = $this->checkDeclineLabelClarity($site);
        $results[] = $this->checkNoMarketingNudgeInDescription($site);
        $results[] = $this->checkDescriptionLength($site);
        $results[] = $this->checkPairedTokenOverrides($site);

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
    private function checkPrivacyPolicyUrl(object $settings): array
    {
        $url = (string) $settings->get('simplecmp.privacyPolicyUrl', '');
        if ($url !== '' && $url !== '#') {
            return $this->pass('privacy-policy-url', '1.5');
        }
        return $this->fail('privacy-policy-url', '1.5', 'critical');
    }

    /**
     * @return Result
     */
    private function checkFirstLayerReject(object $settings): array
    {
        // TYPO3 currently doesn't expose `hideDeclineAll` through any
        // site setting — the FE asset listener always sends the
        // bundle's safe default (`hideDeclineAll: false` implicit).
        // Surface the check anyway so the BE makes it visible that
        // the property is being honoured.
        if ((bool) $settings->get('simplecmp.hideDeclineAll', false) === true) {
            return $this->fail('first-layer-reject', '1.3', 'critical');
        }
        return $this->pass('first-layer-reject', '1.3');
    }

    /**
     * @return Result
     */
    private function checkOptInDefaults(): array
    {
        $services = $this->serviceRepository->findAll();
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
    private function checkPreConsentBlocking(object $settings): array
    {
        if ((bool) $settings->get('simplecmp.universalBlocking.enabled', true) === true) {
            return $this->pass('pre-consent-blocking', '1.7');
        }
        return $this->fail('pre-consent-blocking', '1.7', 'critical');
    }

    /**
     * @return Result
     */
    private function checkPersistentRevocationTrigger(object $settings): array
    {
        // The FE asset listener always passes a `floatingTrigger` to
        // `cmp.init()`, using `simplecmp.floatingTriggerLabel` as the
        // visible text. If the admin has cleared the label, we treat
        // the trigger as effectively disabled — there's no visible
        // affordance to reopen consent.
        $label = (string) $settings->get('simplecmp.floatingTriggerLabel', '');
        if (trim($label) !== '') {
            return $this->pass('persistent-revocation-trigger', '1.6');
        }
        return $this->fail('persistent-revocation-trigger', '1.6', 'warning');
    }

    /**
     * @return Result
     */
    private function checkImprintUrlDach(object $settings): array
    {
        $url = (string) $settings->get('simplecmp.imprintUrl', '');
        if ($url !== '' && $url !== '#') {
            return $this->pass('imprint-url-dach', '1.5');
        }
        return $this->fail('imprint-url-dach', '1.5', 'warning');
    }

    /**
     * @return Result
     */
    private function checkServicesHavePurposes(): array
    {
        $services = $this->serviceRepository->findAll();
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
     * @return Result
     */
    private function checkDeclineLabelClarity(Site $site): array
    {
        $hits = $this->scanOverrideKey(
            $site->getIdentifier(),
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
     * @return Result
     */
    private function checkNoMarketingNudgeInDescription(Site $site): array
    {
        $hits = $this->scanOverrideKey(
            $site->getIdentifier(),
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
     * @return Result
     */
    private function checkDescriptionLength(Site $site): array
    {
        $rows = $this->overrideRepository->findBySite($site->getIdentifier());
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
                    '[%s] %d Zeichen — unter %d, vermutlich fehlt die Zweck-/Verantwortlicher-Information für „informierte" Einwilligung.',
                    $lang,
                    $len,
                    self::DESCRIPTION_MIN_CHARS,
                );
            } elseif ($len > self::DESCRIPTION_MAX_CHARS) {
                $issues[] = sprintf(
                    '[%s] %d Zeichen — über %d; Overloading-Risiko (EDPB 03/2022). Auf das Wesentliche kürzen oder Details ins Modal verschieben.',
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
     * @return Result
     */
    private function checkPairedTokenOverrides(Site $site): array
    {
        $tokens = $this->themeRepository->findBySite($site->getIdentifier()) ?? [];
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
                    '%s — „%s" überschrieben, „%s" auf Default',
                    $pair['label'],
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
    private function scanOverrideKey(
        string $siteIdentifier,
        string $dottedKey,
        array $patterns,
        bool $includeMatchedPhrase,
    ): array {
        $rows = $this->overrideRepository->findBySite($siteIdentifier);
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
}
