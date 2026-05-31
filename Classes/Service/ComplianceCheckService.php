<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Core\Site\Entity\Site;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;

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
 *     title: string,
 *     detail: string,
 *     passed: bool,
 * }
 */
final readonly class ComplianceCheckService
{
    public function __construct(
        private ServiceRepository $serviceRepository,
    ) {
    }

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

        return $results;
    }

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
            return $this->pass(
                'privacy-policy-url',
                '1.5',
                'Privacy-policy URL configured',
                'Privacy policy URL is set.',
            );
        }
        return $this->fail(
            'privacy-policy-url',
            '1.5',
            'critical',
            'Privacy-policy URL configured',
            'GDPR Art. 13 requires the privacy policy to be linked before consent is captured. '
            . 'Set `simplecmp.privacyPolicyUrl` in the site’s settings.',
        );
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
            return $this->fail(
                'first-layer-reject',
                '1.3',
                'critical',
                '"Reject all" available on first layer',
                'VG Hannover 10 A 5385/22 (19.03.2025) and the EDPB Cookie Banner Taskforce '
                . 'require a first-layer Reject affordance. The site setting '
                . '`simplecmp.hideDeclineAll` is true; switch it to false (or remove it).',
            );
        }
        return $this->pass(
            'first-layer-reject',
            '1.3',
            '"Reject all" available on first layer',
            'Reject affordance is present on the first banner layer.',
        );
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
            return $this->pass(
                'opt-in-defaults',
                '1.1',
                'Non-essential services default to OFF',
                'All non-essential services default to OFF.',
            );
        }
        $sample = implode(', ', array_slice($offenders, 0, 5));
        $more = count($offenders) > 5 ? sprintf(', … (%d more)', count($offenders) - 5) : '';
        return $this->fail(
            'opt-in-defaults',
            '1.1',
            'critical',
            'Non-essential services default to OFF',
            sprintf(
                '%d non-essential service(s) have `default: true` (pre-consent granted): %s%s. '
                . 'Pre-ticked consent fails Planet49 (CJEU C-673/17) and BGH Cookie II. '
                . 'Either mark the service required (if it truly is essential) or remove '
                . 'the default flag in the SimpleCMP service registry.',
                count($offenders),
                $sample,
                $more,
            ),
        );
    }

    /**
     * @return Result
     */
    private function checkPreConsentBlocking(object $settings): array
    {
        if ((bool) $settings->get('simplecmp.universalBlocking.enabled', true) === true) {
            return $this->pass(
                'pre-consent-blocking',
                '1.7',
                'Pre-consent tracking blocked',
                'Pre-consent runtime blocking is enabled (`simplecmp.universalBlocking.enabled`).',
            );
        }
        return $this->fail(
            'pre-consent-blocking',
            '1.7',
            'critical',
            'Pre-consent tracking blocked',
            'Without `simplecmp.universalBlocking.enabled`, third-party scripts can dispatch '
            . 'requests before the visitor has chosen — § 25 TDDDG and Art. 5(3) ePrivacy '
            . 'require prior consent for any non-essential storage/access. Enable the '
            . 'setting in the site configuration.',
        );
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
            return $this->pass(
                'persistent-revocation-trigger',
                '1.6',
                'Persistent revocation trigger enabled',
                'Persistent revocation trigger is configured with a non-empty label.',
            );
        }
        return $this->fail(
            'persistent-revocation-trigger',
            '1.6',
            'warning',
            'Persistent revocation trigger enabled',
            'GDPR Art. 7(3) demands withdrawal to be as easy as granting consent. With an '
            . 'empty `simplecmp.floatingTriggerLabel`, the visitor has no visible affordance '
            . 'to reopen the consent banner. Set a label (e.g. "Cookie-Einstellungen").',
        );
    }

    /**
     * @return Result
     */
    private function checkImprintUrlDach(object $settings): array
    {
        $url = (string) $settings->get('simplecmp.imprintUrl', '');
        if ($url !== '' && $url !== '#') {
            return $this->pass(
                'imprint-url-dach',
                '1.5',
                'Imprint URL configured (DACH compliance)',
                'Imprint URL is set.',
            );
        }
        return $this->fail(
            'imprint-url-dach',
            '1.5',
            'warning',
            'Imprint URL configured (DACH compliance)',
            'German TMG / Austrian ECG / Swiss UWG require a separately reachable Impressum. '
            . 'Surface the link next to the privacy policy in the banner by setting '
            . '`simplecmp.imprintUrl` in the site settings. Skip this finding only if '
            . 'the site is not targeted at DACH visitors.',
        );
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
            return $this->pass(
                'services-have-purposes',
                '1.4',
                'Each service declares processing purposes',
                'All services declare at least one purpose.',
            );
        }
        $sample = implode(', ', array_slice($offenders, 0, 5));
        $more = count($offenders) > 5 ? sprintf(', … (%d more)', count($offenders) - 5) : '';
        return $this->fail(
            'services-have-purposes',
            '1.4',
            'warning',
            'Each service declares processing purposes',
            sprintf(
                '%d service(s) have no purposes declared: %s%s. EDPB 05/2020 §42 requires '
                . 'consent to be specific per purpose. Tag each service with at least one '
                . 'purpose category in the SimpleCMP service registry.',
                count($offenders),
                $sample,
                $more,
            ),
        );
    }

    /**
     * @return Result
     */
    private function pass(string $id, string $section, string $title, string $detail): array
    {
        return [
            'id' => $id,
            'section' => $section,
            'severity' => 'info',
            'title' => $title,
            'detail' => $detail,
            'passed' => true,
        ];
    }

    /**
     * @return Result
     */
    private function fail(
        string $id,
        string $section,
        string $severity,
        string $title,
        string $detail,
    ): array {
        return [
            'id' => $id,
            'section' => $section,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'passed' => false,
        ];
    }
}
