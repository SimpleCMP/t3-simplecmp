<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Google Analytics 4 tracker (gtag.js).
 *
 * Required YAML keys:
 *   - measurementId  `G-XXXXXXXXXX` from the GA4 admin
 *
 * Optional:
 *   - anonymizeIp    bool (default true) — emits `gtag('set', { ip_anonymization: true })`
 *                    before `config`. Default ON because in DACH the
 *                    IP-anonymization is part of the BfDI / DSK
 *                    "minimum reasonable" interpretation.
 *   - consentMode    bool (default true) — emits
 *                    `gtag('consent', 'default', { ad_storage: 'denied',
 *                    analytics_storage: 'denied' })` so GA4 respects
 *                    Google's Consent Mode v2 contract. The CMP grants
 *                    `analytics_storage: 'granted'` AFTER the user
 *                    accepts (not yet wired — see TODO at the bottom).
 *   - serviceId      string — override the default `service_id`.
 */
final readonly class Ga4Provider implements TrackerProviderInterface
{
    public function getType(): string
    {
        return 'ga4';
    }

    public function getDefaultServiceId(): string
    {
        return 'ga4';
    }

    public function buildServiceData(array $config): array
    {
        $measurementId = $this->requireMeasurementId($config);
        $serviceId = (string) ($config['serviceId'] ?? $this->getDefaultServiceId());

        return [
            'id' => $serviceId,
            'name' => 'Google Analytics 4',
            'vendor' => 'Google LLC',
            'vendorCountry' => 'US',
            'vendorOptOutUrl' => 'https://tools.google.com/dlpage/gaoptout',
            'purposes' => ['analytics'],
            'privacyPolicyUrl' => 'https://policies.google.com/privacy',
            'description' => sprintf(
                'Google Analytics 4 (measurement id %s). Sends pseudonymous traffic data to Google in the US.',
                $measurementId,
            ),
            'matches' => [
                // The loader and the collect-beacons share these origins.
                // *.analytics.google.com is the modern endpoint, the
                // www.google-analytics.com hostname remains for legacy
                // gtag.js install flows.
                'origins' => [
                    'www.googletagmanager.com',
                    'www.google-analytics.com',
                    '*.analytics.google.com',
                    '*.google-analytics.com',
                ],
                'cookies' => [
                    '_ga',
                    '_ga_*',
                    '_gid',
                    '_gat',
                    '_gat_*',
                ],
            ],
            'retention' => [
                '_ga' => '2 years',
                '_ga_*' => '2 years',
                '_gid' => '24 hours',
                '_gat*' => '1 minute',
            ],
        ];
    }

    public function getLoaderUrl(array $config): ?string
    {
        $measurementId = $this->requireMeasurementId($config);
        return 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($measurementId);
    }

    public function getBootstrapInlineScript(array $config): string
    {
        $measurementId = $this->requireMeasurementId($config);
        $anonymizeIp = (bool) ($config['anonymizeIp'] ?? true);
        $consentMode = (bool) ($config['consentMode'] ?? true);

        $measurementJson = json_encode($measurementId, JSON_THROW_ON_ERROR);

        $lines = [
            'window.dataLayer = window.dataLayer || [];',
            'function gtag(){dataLayer.push(arguments);}',
        ];

        if ($consentMode) {
            // Consent Mode v2 — deny everything by default. The CMP
            // grants `analytics_storage` after the user accepts.
            // @todo wire `cmp.on('consent', ...)` to emit
            //       gtag('consent', 'update', {analytics_storage: 'granted'}).
            //       Engine now ships this as the opt-in `consentMode` hook
            //       (REQ-N10 / ADR-0016 upstream). Integration + the
            //       block-vs-signal-gate posture:
            //       docs/decisions/2026-06-12-consent-mode-v2-tracker-wiring.md
            //       (with this default + no update + the loader load-gated,
            //       GA4 likely stays denied AFTER consent today — verify.)
            $lines[] = "gtag('consent', 'default', {"
                . "'ad_storage': 'denied',"
                . "'ad_user_data': 'denied',"
                . "'ad_personalization': 'denied',"
                . "'analytics_storage': 'denied',"
                . "'wait_for_update': 500"
                . "});";
        }

        $lines[] = "gtag('js', new Date());";

        $configOptions = [];
        if ($anonymizeIp) {
            $configOptions['anonymize_ip'] = true;
        }
        if ($configOptions !== []) {
            $configOptionsJson = json_encode((object) $configOptions, JSON_THROW_ON_ERROR);
            $lines[] = "gtag('config', {$measurementJson}, {$configOptionsJson});";
        } else {
            $lines[] = "gtag('config', {$measurementJson});";
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requireMeasurementId(array $config): string
    {
        $value = $config['measurementId'] ?? null;
        if (!is_string($value) || !preg_match('/^G-[A-Z0-9]+$/', $value)) {
            throw new \InvalidArgumentException(
                'GA4 tracker config: `measurementId` is required and must match `G-XXXXXXXX` (uppercase).',
            );
        }
        return $value;
    }
}
