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
